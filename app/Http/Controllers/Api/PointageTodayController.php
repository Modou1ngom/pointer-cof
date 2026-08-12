<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Pointage;
use App\Services\Pointage\PointageFicheHorairesService;
use App\Services\Pointage\PointageHorairesAjustementService;
use App\Support\MobileApiGeolocation;
use App\Support\PointageJourSemaine;
use App\Support\PointageRhSettingsMerger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Synthèse pointage du jour pour le tableau de bord mobile (CofiPointe).
 */
class PointageTodayController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        PointageRhSettingsMerger::mergeStoredPayloadIntoConfig();

        /** @var \App\Models\User $user */
        $user = $request->user();
        $today = Carbon::today();
        $start = $today->copy()->startOfDay();
        $end = $today->copy()->endOfDay();

        $arriveeAt = null;
        $departAt = null;
        $isLate = false;
        $source = null;
        $arr = null;
        $dep = null;
        $autoFerieOnly = false;

        $pointages = Pointage::query()
            ->where('user_id', $user->id)
            ->whereBetween('clocked_at', [$start, $end])
            ->orderBy('clocked_at')
            ->get();

        $realPointages = $pointages->filter(fn (Pointage $p) => ! $p->isSynthetic())->values();
        $syntheticPointages = $pointages->filter(fn (Pointage $p) => $p->isSynthetic())->values();
        $autoFerieOnly = $realPointages->isEmpty() && $syntheticPointages->isNotEmpty();

        // Le mobile n’affiche que les pointages réels (scan / biométrie), pas les synthétiques.
        $arr = $realPointages->where('type', 'arrivee')->sortBy('clocked_at')->first();
        $dep = $realPointages->where('type', 'depart')->sortByDesc('clocked_at')->first();

        if ($arr !== null) {
            $arriveeAt = $arr->clocked_at;
            $isLate = $arr->statut === 'retard';
            $source = 'pointage';
        }
        if ($dep !== null) {
            $departAt = $dep->clocked_at;
            $source = 'pointage';
        }

        if ($arriveeAt === null || $departAt === null) {
            $attendances = Attendance::query()
                ->where('user_id', $user->id)
                ->whereBetween('recorded_at', [$start, $end])
                ->orderBy('recorded_at')
                ->get()
                ->filter(function (Attendance $a) {
                    $payload = (string) ($a->qr_payload ?? '');

                    // Exclure les lignes créées par déclaration RH (heures fictives 08:00/17:00).
                    return ! str_starts_with($payload, 'declaration:');
                });

            if ($arriveeAt === null) {
                $checkin = $attendances->whereIn('type', ['checkin', 'arrivee'])->sortBy('recorded_at')->first();
                if ($checkin !== null) {
                    $arriveeAt = $checkin->recorded_at;
                    $source = $source ?? 'attendance';
                }
            }
            if ($departAt === null) {
                $checkout = $attendances->whereIn('type', ['checkout', 'depart'])->sortByDesc('recorded_at')->first();
                if ($checkout !== null) {
                    $departAt = $checkout->recorded_at;
                    $source = $source ?? 'attendance';
                }
            }
        }

        $checkInIso = $arriveeAt?->toIso8601String();
        $checkOutIso = $departAt?->toIso8601String();

        $status = 'pending';
        if ($checkInIso !== null && $checkOutIso !== null) {
            $status = 'present';
        } elseif ($checkInIso !== null) {
            $status = 'partial';
        } elseif ($autoFerieOnly) {
            $status = 'ferie_auto';
        }

        $heureArrivee = (string) config('pointage.heure_arrivee', '08:00');
        $heureDepart = (string) config('pointage.heure_depart', '17:00');
        $heureArriveeAjustee = (string) config('pointage.heure_arrivee_ajustee', $heureArrivee);
        $heureDepartAjustee = (string) config('pointage.heure_depart_ajustee', $heureDepart);

        $lite = $request->boolean('lite');

        $ficheRow = [
            'h_arrivee' => '',
            'h_depart' => '',
            'h_ajust_arrivee' => '',
            'h_ajust_depart' => '',
            'total' => null,
            'total_ajust_calc' => null,
            'total_ajust_journee' => null,
        ];
        if (! $lite) {
            $ficheRow = app(PointageFicheHorairesService::class)->buildRow(
                $user,
                $today->toDateString(),
                $arriveeAt,
                $departAt,
            );
        }

        $journeeComplete = $arriveeAt !== null && $departAt !== null;

        $payload = [
            'date' => $today->toDateString(),
            'check_in' => $checkInIso,
            'check_out' => $checkOutIso,
            'checkIn' => $checkInIso,
            'checkOut' => $checkOutIso,
            'entry' => $arr !== null ? $arr->heureAffichee() : ($arriveeAt?->format('H:i')),
            'exit' => $dep !== null ? $dep->heureAffichee() : ($departAt?->format('H:i')),
            'status' => $status,
            'statut' => $this->statutLabel($status, $isLate, $autoFerieOnly),
            'is_late' => $isLate,
            'isLate' => $isLate,
            'auto_ferie' => $autoFerieOnly,
            'autoFerie' => $autoFerieOnly,
            'journee_complete' => $journeeComplete,
            'journeeComplete' => $journeeComplete,
            'synced' => true,
            'office_zone' => MobileApiGeolocation::officeZoneForUser($user),
            // Toujours exposer les horaires prévus (section « Mes horaires » mobile).
            'scheduled_arrival' => $heureArrivee,
            'scheduled_departure' => $heureDepart,
            'heure_arrivee_prevue' => $heureArrivee,
            'heure_depart_prevue' => $heureDepart,
        ];

        if (! $lite) {
            $payload = array_merge($payload, [
                'entree' => $payload['entry'],
                'sortie' => $payload['exit'],
                'entry_reelle' => $arr !== null ? $arr->heureReelleAffichee() : ($arriveeAt?->format('H:i')),
                'exit_reelle' => $dep !== null ? $dep->heureReelleAffichee() : ($departAt?->format('H:i')),
                'h_arrivee' => $ficheRow['h_arrivee'] !== '' ? $ficheRow['h_arrivee'] : null,
                'h_depart' => $ficheRow['h_depart'] !== '' ? $ficheRow['h_depart'] : null,
                'h_ajust_arrivee' => $ficheRow['h_ajust_arrivee'] !== '' ? $ficheRow['h_ajust_arrivee'] : null,
                'h_ajust_depart' => $ficheRow['h_ajust_depart'] !== '' ? $ficheRow['h_ajust_depart'] : null,
                'heure_arrivee_ajustee' => $heureArriveeAjustee,
                'heure_depart_ajustee' => $heureDepartAjustee,
                'total' => $journeeComplete ? $ficheRow['total'] : null,
                'total_ajust_calc' => $journeeComplete ? $ficheRow['total_ajust_calc'] : null,
                'total_ajust_journee' => $journeeComplete ? $ficheRow['total_ajust_journee'] : null,
                'totalAjust' => $journeeComplete ? $ficheRow['total_ajust_calc'] : null,
                'totalAjustJournee' => $journeeComplete ? $ficheRow['total_ajust_journee'] : null,
                'source' => $source,
                'geolocation' => MobileApiGeolocation::clientHints(),
                'plages_pointage' => app(PointageHorairesAjustementService::class)->plagesConfigForApi(),
                'jour_pointage' => PointageJourSemaine::windowInfo($today),
            ]);
        }

        return response()->json(array_merge($payload, [
            'data' => $payload,
        ]));
    }

    private function statutLabel(string $status, bool $isLate, bool $autoFerieOnly): string
    {
        if ($autoFerieOnly || $status === 'ferie_auto') {
            return 'Jour férié (auto)';
        }
        if ($status === 'pending') {
            return 'En attente de pointage';
        }
        if ($status === 'partial') {
            return $isLate ? 'En cours (retard)' : 'Entrée enregistrée';
        }

        return $isLate ? 'Présent (retard)' : 'Présent';
    }
}
