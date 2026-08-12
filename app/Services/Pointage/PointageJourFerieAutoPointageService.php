<?php

namespace App\Services\Pointage;

use App\Models\Agence;
use App\Models\Departement;
use App\Models\Pointage;
use App\Models\PointageHoraireProfile;
use App\Models\PointageJourFerie;
use App\Models\Profil;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Pointage automatique de tous les staffs concernés lorsqu'un jour est déclaré férié chômé.
 *
 * Règles :
 *  - Seuls les fériés « chômés » (travaille_avec_majoration = false) déclenchent l'auto-pointage.
 *  - Un seul couple (arrivée + départ) par utilisateur/jour, avec meta.auto_ferie = true.
 *  - Skip si le user a déjà un pointage manuel pour la date (entrée OU sortie).
 *  - Skip si la date tombe sur le week-end du profil horaire (déjà non comptée).
 *  - Si le férié est rattaché à un departement_id, on filtre les staffs sur le département.
 *  - Idempotent : peut être ré-exécuté sans créer de doublons.
 */
final class PointageJourFerieAutoPointageService
{
    public function __construct(
        private readonly PointageHorairesCalendrierService $calendrier,
    ) {}

    /**
     * Applique l'auto-pointage à toutes les dates couvertes par le férié.
     *
     * @return array{processed_dates: int, created_pointages: int, skipped_users: int}
     */
    public function generateForFerie(PointageJourFerie $f, bool $includePastDates = true): array
    {
        $stats = ['processed_dates' => 0, 'created_pointages' => 0, 'skipped_users' => 0];

        if ($f->travaille_avec_majoration) {
            return $stats;
        }

        foreach ($this->datesCouvertesParFerie($f) as $date) {
            if (! $includePastDates && $date->lt(Carbon::today())) {
                continue;
            }
            if ($date->gt(Carbon::today()->addYear())) {
                continue;
            }

            $res = $this->generateForDate($date, $f);
            $stats['processed_dates']++;
            $stats['created_pointages'] += $res['created_pointages'];
            $stats['skipped_users'] += $res['skipped_users'];
        }

        return $stats;
    }

    /**
     * Détecte le férié applicable pour la date et crée les pointages auto si chômé.
     *
     * @return array{created_pointages: int, skipped_users: int, ferie?: PointageJourFerie}
     */
    public function generateForDate(Carbon $date, ?PointageJourFerie $ferieHint = null): array
    {
        $date = $date->copy()->startOfDay();
        $ferie = $ferieHint;
        if ($ferie === null) {
            $ferie = $this->calendrier->feriePourDate($date);
        }

        if ($ferie === null) {
            return ['created_pointages' => 0, 'skipped_users' => 0];
        }

        if ($ferie->travaille_avec_majoration) {
            return [
                'created_pointages' => 0,
                'skipped_users' => 0,
                'ferie' => $ferie,
                'reason' => 'ferie_travaille_majoration',
            ];
        }

        $profile = $this->profileHoraireGlobal();
        if ($profile !== null && $this->calendrier->isWeekend($date, $profile)) {
            return ['created_pointages' => 0, 'skipped_users' => 0, 'ferie' => $ferie];
        }

        $users = $this->staffEligibles($ferie);
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($users, $date, $ferie, &$created, &$skipped): void {
            $heureArrivee = (string) config('pointage.heure_arrivee_ajustee', config('pointage.heure_arrivee', '08:00'));
            $heureDepart = (string) config('pointage.heure_depart_ajustee', config('pointage.heure_depart', '17:00'));

            foreach ($users as $user) {
                $agence = $this->agenceDomiciliaire($user);
                if ($agence === null) {
                    $skipped++;

                    continue;
                }

                $existsManuel = Pointage::query()
                    ->where('user_id', $user->id)
                    ->whereDate('clocked_at', $date)
                    ->whereNotIn('statut', ['ferie_auto'])
                    ->exists();

                if ($existsManuel) {
                    $skipped++;

                    continue;
                }

                $created += $this->createPair($user, $agence, $date, $ferie, $heureArrivee, $heureDepart);
            }
        });

        return [
            'created_pointages' => $created,
            'skipped_users' => $skipped,
            'ferie' => $ferie,
        ];
    }

    /**
     * Lance l'auto-pointage pour aujourd'hui (utilisé par la commande planifiée).
     *
     * @return array{created_pointages: int, skipped_users: int, ferie?: PointageJourFerie}
     */
    public function generateForToday(): array
    {
        return $this->generateForDate(Carbon::today());
    }

    private function createPair(
        User $user,
        Agence $agence,
        Carbon $date,
        PointageJourFerie $ferie,
        string $heureArrivee,
        string $heureDepart,
    ): int {
        $created = 0;

        $arriveeAt = $this->dateWithTime($date, $heureArrivee);
        $departAt = $this->dateWithTime($date, $heureDepart);

        $meta = [
            'auto_ferie' => true,
            'ferie_id' => $ferie->id,
            'ferie_libelle' => $ferie->libelle,
            'ferie_source' => $ferie->source,
            'note' => 'Pointage automatique — jour férié chômé',
        ];

        $alreadyArrivee = Pointage::query()
            ->where('user_id', $user->id)
            ->where('type', 'arrivee')
            ->whereDate('clocked_at', $date)
            ->exists();

        if (! $alreadyArrivee) {
            Pointage::query()->create([
                'user_id' => $user->id,
                'agence_id' => $agence->id,
                'type' => 'arrivee',
                'clocked_at' => $arriveeAt,
                'latitude' => $agence->latitude,
                'longitude' => $agence->longitude,
                'qr_verified' => false,
                'biometric_ok' => false,
                'statut' => 'ferie_auto',
                'meta' => array_merge($meta, [
                    'heure_reelle' => $heureArrivee,
                    'heure_effective' => $heureArrivee,
                    'heure_effective_at' => $arriveeAt->toIso8601String(),
                    'ajustement_applique' => true,
                    'plage' => 'arrivee',
                    'requested_type' => 'auto',
                    'type_auto_corrected' => false,
                ]),
            ]);
            $created++;
        }

        // Ne créer la sortie auto qu’après l’heure de départ prévue
        // (évite d’afficher « Sortie 17h00 » dès le matin).
        $now = Carbon::now();
        if ($now->lt($departAt)) {
            return $created;
        }

        $alreadyDepart = Pointage::query()
            ->where('user_id', $user->id)
            ->where('type', 'depart')
            ->whereDate('clocked_at', $date)
            ->exists();

        if (! $alreadyDepart) {
            Pointage::query()->create([
                'user_id' => $user->id,
                'agence_id' => $agence->id,
                'type' => 'depart',
                'clocked_at' => $departAt,
                'latitude' => $agence->latitude,
                'longitude' => $agence->longitude,
                'qr_verified' => false,
                'biometric_ok' => false,
                'statut' => 'ferie_auto',
                'meta' => array_merge($meta, [
                    'heure_reelle' => $heureDepart,
                    'heure_effective' => $heureDepart,
                    'heure_effective_at' => $departAt->toIso8601String(),
                    'ajustement_applique' => true,
                    'plage' => 'depart',
                    'requested_type' => 'auto',
                    'type_auto_corrected' => false,
                ]),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @return iterable<int, Carbon>
     */
    private function datesCouvertesParFerie(PointageJourFerie $f): iterable
    {
        if ($f->date_unique === null) {
            return [];
        }

        if ($f->recurrence_annuelle) {
            $year = (int) ($f->annee ?: now()->year);
            $month = (int) $f->date_unique->month;
            $day = (int) $f->date_unique->day;
            if (! checkdate($month, $day, $year)) {
                return [];
            }

            return [Carbon::createFromDate($year, $month, $day)->startOfDay()];
        }

        $start = $f->date_unique->copy()->startOfDay();
        $end = ($f->date_fin ?? $f->date_unique)->copy()->startOfDay();

        return CarbonPeriod::create($start, $end);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function staffEligibles(PointageJourFerie $ferie): EloquentCollection
    {
        $q = User::query()
            ->where('is_active', true)
            ->whereHas('agences')
            ->with(['agences', 'profil']);

        if ($ferie->departement_id !== null) {
            $departementNom = Departement::query()->where('id', $ferie->departement_id)->value('nom');
            if (is_string($departementNom) && $departementNom !== '') {
                $q->whereHas('profil', function ($w) use ($departementNom) {
                    $w->whereRaw('LOWER(TRIM(departement)) = ?', [strtolower(trim($departementNom))]);
                });
            }
        }

        return $q->get();
    }

    private function agenceDomiciliaire(User $user): ?Agence
    {
        if (! $user->relationLoaded('agences')) {
            $user->load('agences');
        }
        $agence = $user->agences->firstWhere('pivot.is_default', true);
        if ($agence === null && $user->agences->isNotEmpty()) {
            $agence = $user->agences->first();
        }

        return $agence;
    }

    private function profileHoraireGlobal(): ?PointageHoraireProfile
    {
        return PointageHoraireProfile::query()
            ->where('scope_type', 'global')
            ->where('actif', true)
            ->orderBy('id')
            ->first()
            ?: PointageHoraireProfile::query()->orderBy('id')->first();
    }

    private function dateWithTime(Carbon $date, string $hhmm): Carbon
    {
        $parts = explode(':', $hhmm);
        $h = (int) ($parts[0] ?? 8);
        $m = (int) ($parts[1] ?? 0);

        return $date->copy()->setTime($h, $m, 0);
    }
}
