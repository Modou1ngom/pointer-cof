<?php

namespace App\Services\Pointage;

use App\Models\PointageDeclaration;
use App\Models\User;
use App\Support\PointageDeclarationTypes;
use Carbon\Carbon;

/**
 * Plages horaires de pointage (arrivée / départ) et heures effectives (réelles ou ajustées).
 */
final class PointageHorairesAjustementService
{
    /**
     * @return array{
     *     ok: bool,
     *     type?: 'arrivee'|'depart',
     *     message?: string,
     *     plage?: string,
     *     requested_type?: string|null,
     *     type_auto_corrected?: bool,
     * }
     */
    public function resolveType(Carbon $at, ?string $requested = null): array
    {
        $requested = $this->normalizeRequestedType($requested);
        $inArrivee = $this->isWithinPlage($at, $this->plageArriveeDebut(), $this->plageArriveeFin());
        $inDepart = $this->isWithinPlage($at, $this->plageDepartDebut(), $this->plageDepartFin());

        if (! $inArrivee && ! $inDepart) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Pointage refusé : horaire %s hors plage autorisée (arrivée %s–%s, départ %s–%s).',
                    $at->format('H:i'),
                    $this->plageArriveeDebut(),
                    $this->plageArriveeFin(),
                    $this->plageDepartDebut(),
                    $this->plageDepartFin()
                ),
            ];
        }

        // Honorer le type demandé par l’app dès qu’il est dans sa plage
        // (évite de forcer une « arrivée » alors que l’utilisateur veut une sortie).
        if ($requested === 'arrivee' && $inArrivee) {
            return [
                'ok' => true,
                'type' => 'arrivee',
                'plage' => 'arrivee',
                'requested_type' => $requested,
                'type_auto_corrected' => false,
            ];
        }
        if ($requested === 'depart' && $inDepart) {
            return [
                'ok' => true,
                'type' => 'depart',
                'plage' => 'depart',
                'requested_type' => $requested,
                'type_auto_corrected' => false,
            ];
        }

        if ($inArrivee && $inDepart) {
            return [
                'ok' => false,
                'message' => 'Heure ambiguë : contactez le RH (plages arrivée et départ se chevauchent).',
            ];
        }

        $inferred = $inArrivee ? 'arrivee' : 'depart';
        $plage = $inferred;
        $typeAutoCorrected = $requested !== null && $requested !== $inferred;

        return [
            'ok' => true,
            'type' => $inferred,
            'plage' => $plage,
            'requested_type' => $requested,
            'type_auto_corrected' => $typeAutoCorrected,
        ];
    }

    /**
     * @return array{
     *     statut: string,
     *     heure_reelle: string,
     *     heure_effective: string,
     *     heure_effective_at: Carbon,
     *     ajustement_applique: bool,
     *     plage: string|null,
     *     allaitement?: array{sens: string, heure: string}|null,
     * }
     */
    public function computeEffectivePunch(
        Carbon $clockedAt,
        string $type,
        ?string $plage = null,
        ?User $user = null,
    ): array {
        $date = $clockedAt->copy()->startOfDay();
        $heureArrivee = (string) config('pointage.heure_arrivee', '08:00');
        $heureDepart = (string) config('pointage.heure_depart', '17:00');
        $heureArriveeAjustee = (string) config('pointage.heure_arrivee_ajustee', $heureArrivee);
        $heureDepartAjustee = (string) config('pointage.heure_depart_ajustee', $heureDepart);
        $toleranceMinutes = (int) config('pointage.tolerance_minutes', 10);

        $allaitement = $user !== null
            ? $this->resolveAllaitementValide((int) $user->id, $date)
            : null;

        // Allaitement entrée : l’arrivée prévue devient l’heure déclarée (ex. 09:00 → retard après 09:00 + tolérance).
        if ($allaitement !== null && $allaitement['sens'] === 'entree' && $type === 'arrivee') {
            $heureArrivee = $allaitement['heure'];
            $heureArriveeAjustee = $allaitement['heure'];
        }

        $limiteRetard = $date->copy()->setTimeFromTimeString($heureArrivee)->addMinutes($toleranceMinutes);
        $limiteDepart = $date->copy()->setTimeFromTimeString($heureDepart);

        $statut = 'normal';
        $ajustementApplique = false;
        $heureEffective = $clockedAt->format('H:i');

        if ($type === 'arrivee') {
            // Avant / dans la tolérance → effective = heure ajustée ; après → réelle + retard.
            if ($clockedAt->greaterThan($limiteRetard)) {
                $statut = 'retard';
                $heureEffective = $clockedAt->format('H:i');
            } else {
                $ajustementApplique = true;
                $heureEffective = $this->formatTimeShort($heureArriveeAjustee);
            }
        } else {
            // Allaitement sortie (ex. 16:00) : un pointage départ à partir de cette heure
            // est ramené à l’heure de départ prévue (17:00).
            if (
                $allaitement !== null
                && $allaitement['sens'] === 'sortie'
                && $clockedAt->gte($date->copy()->setTimeFromTimeString($allaitement['heure']))
                && $clockedAt->lt($limiteDepart)
            ) {
                $ajustementApplique = true;
                $heureEffective = $this->formatTimeShort($heureDepartAjustee);

                return $this->buildResult(
                    $clockedAt,
                    $date,
                    $statut,
                    $heureEffective,
                    $ajustementApplique,
                    $plage,
                    $allaitement,
                );
            }

            // Symétrique à l’entrée (réf. 17:00) :
            // avant 17:00 → effective = réelle ; à partir de 17:00 → effective = heure ajustée.
            if ($clockedAt->lt($limiteDepart)) {
                $heureEffective = $clockedAt->format('H:i');
            } else {
                $ajustementApplique = true;
                $heureEffective = $this->formatTimeShort($heureDepartAjustee);
            }
        }

        return $this->buildResult(
            $clockedAt,
            $date,
            $statut,
            $heureEffective,
            $ajustementApplique,
            $plage,
            $allaitement,
        );
    }

    /**
     * @return array{sens: string, heure: string}|null
     */
    public function resolveAllaitementValide(int $userId, Carbon $day): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $dayStr = $day->toDateString();

        $declaration = PointageDeclaration::query()
            ->where('user_id', $userId)
            ->where('type', 'allaitement')
            ->where('statut', 'valide')
            ->whereDate('date_concernee', '<=', $dayStr)
            ->where(function ($q) use ($dayStr): void {
                $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $dayStr);
            })
            ->orderByDesc('id')
            ->first();

        if ($declaration === null) {
            return null;
        }

        $sens = PointageDeclarationTypes::allaitementSens(
            $declaration->heure_debut !== null ? (string) $declaration->heure_debut : null,
            $declaration->heure_fin !== null ? (string) $declaration->heure_fin : null,
        );
        $heure = PointageDeclarationTypes::allaitementHeure(
            $declaration->heure_debut !== null ? (string) $declaration->heure_debut : null,
            $declaration->heure_fin !== null ? (string) $declaration->heure_fin : null,
        );

        if ($sens === null || $heure === null) {
            return null;
        }

        return [
            'sens' => $sens,
            'heure' => $this->normalizeHhMm($heure),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function plagesConfigForApi(): array
    {
        return [
            'arrivee' => [
                'debut' => $this->plageArriveeDebut(),
                'fin' => $this->plageArriveeFin(),
            ],
            'depart' => [
                'debut' => $this->plageDepartDebut(),
                'fin' => $this->plageDepartFin(),
            ],
            'heure_arrivee_prevue' => (string) config('pointage.heure_arrivee', '08:00'),
            'heure_depart_prevue' => (string) config('pointage.heure_depart', '17:00'),
            'heure_arrivee_ajustee' => (string) config('pointage.heure_arrivee_ajustee', '08:00'),
            'heure_depart_ajustee' => (string) config('pointage.heure_depart_ajustee', '17:00'),
            'tolerance_minutes' => (int) config('pointage.tolerance_minutes', 10),
        ];
    }

    /**
     * @param  array{sens: string, heure: string}|null  $allaitement
     * @return array{
     *     statut: string,
     *     heure_reelle: string,
     *     heure_effective: string,
     *     heure_effective_at: Carbon,
     *     ajustement_applique: bool,
     *     plage: string|null,
     *     allaitement: array{sens: string, heure: string}|null,
     * }
     */
    private function buildResult(
        Carbon $clockedAt,
        Carbon $date,
        string $statut,
        string $heureEffective,
        bool $ajustementApplique,
        ?string $plage,
        ?array $allaitement,
    ): array {
        $parts = explode(':', $heureEffective);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        $heureEffectiveAt = $date->copy()->setTime($h, $m, 0);

        return [
            'statut' => $statut,
            'heure_reelle' => $clockedAt->format('H:i'),
            'heure_effective' => $heureEffective,
            'heure_effective_at' => $heureEffectiveAt,
            'ajustement_applique' => $ajustementApplique,
            'plage' => $plage,
            'allaitement' => $allaitement,
        ];
    }

    private function normalizeRequestedType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }
        $t = strtolower($type);
        if (in_array($t, ['checkin', 'arrivee', 'entree', 'entrée'], true)) {
            return 'arrivee';
        }
        if (in_array($t, ['checkout', 'depart', 'départ', 'sortie'], true)) {
            return 'depart';
        }

        return in_array($t, ['arrivee', 'depart'], true) ? $t : null;
    }

    private function isWithinPlage(Carbon $at, string $debut, string $fin): bool
    {
        $day = $at->copy()->startOfDay();
        $from = $day->copy()->setTimeFromTimeString($debut);
        $to = $day->copy()->setTimeFromTimeString($fin);
        if ($to->lessThan($from)) {
            return $at->gte($from) || $at->lte($to);
        }

        return $at->gte($from) && $at->lte($to);
    }

    private function formatTimeShort(string $time): string
    {
        $parts = explode(':', $time);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);

        return $m > 0 ? sprintf('%d:%02d', $h, $m) : (string) $h;
    }

    private function normalizeHhMm(string $time): string
    {
        $clean = str_replace(['h', 'H'], ':', trim($time));
        $parts = explode(':', $clean);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);

        return sprintf('%02d:%02d', $h, $m);
    }

    private function plageArriveeDebut(): string
    {
        return (string) config('pointage.plage_arrivee_debut', '07:00');
    }

    private function plageArriveeFin(): string
    {
        return (string) config('pointage.plage_arrivee_fin', '12:00');
    }

    private function plageDepartDebut(): string
    {
        return (string) config('pointage.plage_depart_debut', '15:00');
    }

    private function plageDepartFin(): string
    {
        return (string) config('pointage.plage_depart_fin', '20:00');
    }
}
