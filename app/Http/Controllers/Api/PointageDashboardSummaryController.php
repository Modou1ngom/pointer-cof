<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Pointage;
use App\Models\PointageDeclaration;
use App\Models\PointageHoraireProfile;
use App\Services\Pointage\PointageHorairesCalendrierService;
use App\Support\PointageDeclarationTypes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Compteurs mensuels pour le tableau de bord mobile (CofiPointe).
 * S’incrémentent chaque jour ouvrable du mois en cours et repartent à 0 au 1er du mois suivant.
 */
class PointageDashboardSummaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfDay();

        $joursCalendrier = $this->joursOuvresDansPeriode($monthStart, $today);
        $calendrierSet = array_fill_keys($joursCalendrier, true);
        $joursOuvres = $joursCalendrier;
        $joursSet = $calendrierSet;

        $pointages = Pointage::query()
            ->where('user_id', $user->id)
            ->whereBetween('clocked_at', [$monthStart, $monthEnd])
            ->orderBy('clocked_at')
            ->get()
            ->groupBy(fn (Pointage $p) => $p->clocked_at->toDateString());

        $attendances = Attendance::query()
            ->where('user_id', $user->id)
            ->whereBetween('recorded_at', [$monthStart, $monthEnd])
            ->orderBy('recorded_at')
            ->get()
            ->groupBy(fn (Attendance $a) => $a->recorded_at->toDateString());

        // Inclure aussi les jours pointés même s'ils ne sont pas dans le calendrier ouvrable.
        foreach (array_unique(array_merge($pointages->keys()->all(), $attendances->keys()->all())) as $day) {
            if (! isset($joursSet[$day])) {
                $joursOuvres[] = $day;
                $joursSet[$day] = true;
            }
        }
        sort($joursOuvres);

        $coverage = $this->declarationCoverageByDay(
            $this->declarationsValides($user->id, $monthStart, $today),
            $joursSet,
        );

        $presents = 0;
        $retards = 0;
        $absents = 0;
        $conges = 0;

        foreach ($joursOuvres as $day) {
            /** @var \Illuminate\Support\Collection<int, Pointage> $items */
            $items = $pointages->get($day) ?? collect();
            /** @var \Illuminate\Support\Collection<int, Attendance> $atts */
            $atts = $attendances->get($day) ?? collect();

            $arrivee = $items->firstWhere('type', 'arrivee');
            $depart = $items
                ->filter(fn (Pointage $p) => $p->type === 'depart')
                ->sortByDesc(fn (Pointage $p) => $p->clocked_at->timestamp)
                ->first();

            $hasCheckin = $arrivee !== null
                || $atts->contains(fn (Attendance $a) => in_array($a->type, ['checkin', 'arrivee'], true));
            $hasCheckout = $depart !== null
                || $atts->contains(fn (Attendance $a) => in_array($a->type, ['checkout', 'depart'], true));

            if ($hasCheckin || $hasCheckout) {
                $presents++;
                if ($arrivee !== null && $arrivee->statut === 'retard') {
                    $retards++;
                }

                continue;
            }

            $kind = $coverage[$day] ?? null;
            if (in_array($kind, ['conge_annuel', 'conge_maladie', 'permission_exceptionnelle', 'formation', 'mission'], true)) {
                $conges++;
            } elseif (isset($calendrierSet[$day])) {
                $absents++;
            }
        }

        $payload = [
            'mois' => $today->format('Y-m'),
            'presents' => $presents,
            'retards' => $retards,
            'absents' => $absents,
            'conges' => $conges,
            'presentsCount' => $presents,
            'lateCount' => $retards,
            'absentCount' => $absents,
            'onLeaveCount' => $conges,
        ];

        return response()->json(array_merge($payload, ['data' => $payload]));
    }

    /**
     * @return list<string>
     */
    private function joursOuvresDansPeriode(Carbon $from, Carbon $to): array
    {
        try {
            $calendrier = app(PointageHorairesCalendrierService::class);
            $profile = null;
            if (Schema::hasTable('pointage_horaire_profiles')) {
                $profile = PointageHoraireProfile::query()
                    ->where('scope_type', 'global')
                    ->where('actif', true)
                    ->orderBy('id')
                    ->first()
                    ?? PointageHoraireProfile::query()->orderBy('id')->first();
            }

            $feries = $profile !== null ? $calendrier->feriesChargees() : null;
            $out = [];
            $d = $from->copy()->startOfDay();
            $end = $to->copy()->startOfDay();
            while ($d->lte($end)) {
                if ($profile === null) {
                    if (! $d->isWeekend()) {
                        $out[] = $d->toDateString();
                    }
                } elseif ($calendrier->jourCompteDansBasePresence($d, $profile, $feries)) {
                    $out[] = $d->toDateString();
                }
                $d->addDay();
            }

            return $out;
        } catch (Throwable) {
            $out = [];
            $d = $from->copy()->startOfDay();
            $end = $to->copy()->startOfDay();
            while ($d->lte($end)) {
                if (! $d->isWeekend()) {
                    $out[] = $d->toDateString();
                }
                $d->addDay();
            }

            return $out;
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, PointageDeclaration>
     */
    private function declarationsValides(int $userId, Carbon $from, Carbon $to)
    {
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $hasDateFin = Schema::hasColumn('pointage_declarations', 'date_fin');

        $q = PointageDeclaration::query()
            ->where('user_id', $userId)
            ->where('statut', 'valide')
            ->whereIn('type', PointageDeclarationTypes::TYPES_JUSTIFICATIFS_PRESENCE)
            ->whereDate('date_concernee', '<=', $toDay);

        if ($hasDateFin) {
            $q->where(function ($w) use ($fromDay): void {
                $w->where(function ($x) use ($fromDay): void {
                    $x->whereNotNull('date_fin')->whereDate('date_fin', '>=', $fromDay);
                })->orWhere(function ($x) use ($fromDay): void {
                    $x->whereNull('date_fin')->whereDate('date_concernee', '>=', $fromDay);
                });
            });
        } else {
            $q->whereDate('date_concernee', '>=', $fromDay);
        }

        return $q->orderByDesc('id')->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PointageDeclaration>  $decls
     * @param  array<string, bool>  $joursOuvresSet
     * @return array<string, string>
     */
    private function declarationCoverageByDay($decls, array $joursOuvresSet): array
    {
        $out = [];
        foreach ($decls as $d) {
            $start = $d->date_concernee?->toDateString();
            if ($start === null) {
                continue;
            }
            $end = $d->date_fin?->toDateString() ?? $start;
            $type = PointageDeclarationTypes::normalize((string) $d->type);
            $kind = match ($type) {
                'mission' => 'mission',
                'conge_annuel' => 'conge_annuel',
                'conge_maladie' => 'conge_maladie',
                'permission_exceptionnelle' => 'permission_exceptionnelle',
                'formation' => 'formation',
                default => 'absence',
            };

            $cursor = Carbon::parse($start)->startOfDay();
            $endC = Carbon::parse($end)->startOfDay();
            while ($cursor->lte($endC)) {
                $key = $cursor->toDateString();
                if (isset($joursOuvresSet[$key]) && ! isset($out[$key])) {
                    $out[$key] = $kind;
                }
                $cursor->addDay();
            }
        }

        return $out;
    }
}
