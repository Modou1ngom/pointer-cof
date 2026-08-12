<?php

namespace App\Support;

use App\Models\PointageDeclaration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Déclarations RH validées qui couvrent une journée (pas d’absence injustifiée).
 */
final class PointageDeclarationCouverture
{
    private static function hasDateFinColumn(): bool
    {
        static $cached = null;
        if ($cached === null) {
            $cached = Schema::hasColumn('pointage_declarations', 'date_fin');
        }

        return (bool) $cached;
    }

    /**
     * @param  list<string>|null  $types  null = justificatifs présence (absence)
     * @return array{couvert: bool, type?: string, label?: string, declaration_id?: int}
     */
    public static function pourUserJour(int $userId, Carbon $jour, ?array $types = null): array
    {
        $day = $jour->toDateString();
        $hasDateFin = self::hasDateFinColumn();
        $types = $types ?? PointageDeclarationTypes::TYPES_JUSTIFICATIFS_PRESENCE;

        $d = PointageDeclaration::query()
            ->where('user_id', $userId)
            ->where('statut', 'valide')
            ->whereIn('type', $types)
            ->where(function ($q) use ($day, $hasDateFin): void {
                if ($hasDateFin) {
                    // Plage : début <= jour <= fin
                    $q->where(function ($w) use ($day): void {
                        $w->whereNotNull('date_fin')
                            ->whereDate('date_concernee', '<=', $day)
                            ->whereDate('date_fin', '>=', $day);
                    })->orWhere(function ($w) use ($day): void {
                        // Jour unique (sans date_fin)
                        $w->whereNull('date_fin')
                            ->whereDate('date_concernee', $day);
                    });
                } else {
                    $q->whereDate('date_concernee', $day);
                }
            })
            ->orderByDesc('id')
            ->first();

        if ($d === null) {
            return ['couvert' => false];
        }

        return [
            'couvert' => true,
            'type' => $d->type,
            'label' => PointageDeclarationTypes::label((string) $d->type),
            'declaration_id' => $d->id,
        ];
    }

    /**
     * Prefetch pour une liste d’users / un jour (évite N+1).
     *
     * @param  list<int>  $userIds
     * @param  list<string>|null  $types
     * @return Collection<int, array{couvert: bool, type?: string, label?: string, declaration_id?: int}>
     */
    public static function mapPourUsersJour(array $userIds, Carbon $jour, ?array $types = null): Collection
    {
        $day = $jour->toDateString();
        $hasDateFin = self::hasDateFinColumn();
        $types = $types ?? PointageDeclarationTypes::TYPES_JUSTIFICATIFS_PRESENCE;
        $decls = PointageDeclaration::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->where('statut', 'valide')
            ->whereIn('type', $types)
            ->where(function ($q) use ($day, $hasDateFin): void {
                if ($hasDateFin) {
                    $q->where(function ($w) use ($day): void {
                        $w->whereNotNull('date_fin')
                            ->whereDate('date_concernee', '<=', $day)
                            ->whereDate('date_fin', '>=', $day);
                    })->orWhere(function ($w) use ($day): void {
                        $w->whereNull('date_fin')
                            ->whereDate('date_concernee', $day);
                    });
                } else {
                    $q->whereDate('date_concernee', $day);
                }
            })
            ->orderByDesc('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $out = collect();
        foreach ($userIds as $uid) {
            $d = $decls->get($uid);
            $out->put($uid, $d
                ? [
                    'couvert' => true,
                    'type' => $d->type,
                    'label' => PointageDeclarationTypes::label((string) $d->type),
                    'declaration_id' => $d->id,
                ]
                : ['couvert' => false]);
        }

        return $out;
    }

    /**
     * Labels de justification indexés par date (Y-m-d) pour un utilisateur / période.
     * Une seule requête SQL, puis couverture en mémoire.
     *
     * @return array<string, string> date => label
     */
    public static function labelsPourUserPeriode(int $userId, Carbon $from, Carbon $to): array
    {
        $fromDay = $from->toDateString();
        $toDay = $to->toDateString();
        $hasDateFin = self::hasDateFinColumn();

        $query = PointageDeclaration::query()
            ->where('user_id', $userId)
            ->where('statut', 'valide')
            ->whereIn('type', PointageDeclarationTypes::TYPES_JUSTIFICATIFS_PRESENCE)
            ->whereDate('date_concernee', '<=', $toDay);

        if ($hasDateFin) {
            $query->where(function ($q) use ($fromDay): void {
                $q->where(function ($w) use ($fromDay): void {
                    $w->whereNotNull('date_fin')
                        ->whereDate('date_fin', '>=', $fromDay);
                })->orWhere(function ($w) use ($fromDay): void {
                    $w->whereNull('date_fin')
                        ->whereDate('date_concernee', '>=', $fromDay);
                });
            });
            $decls = $query->orderByDesc('id')->get(['id', 'type', 'date_concernee', 'date_fin']);
        } else {
            $decls = $query
                ->whereDate('date_concernee', '>=', $fromDay)
                ->orderByDesc('id')
                ->get(['id', 'type', 'date_concernee']);
        }

        $out = [];
        foreach ($decls as $d) {
            $start = $d->date_concernee?->toDateString();
            if ($start === null) {
                continue;
            }
            $end = $d->date_fin?->toDateString() ?? $start;
            $cursor = Carbon::parse($start)->startOfDay();
            $endC = Carbon::parse($end)->startOfDay();
            $label = PointageDeclarationTypes::label((string) $d->type);
            while ($cursor->lte($endC)) {
                $key = $cursor->toDateString();
                if ($key >= $fromDay && $key <= $toDay && ! isset($out[$key])) {
                    $out[$key] = $label;
                }
                $cursor->addDay();
            }
        }

        return $out;
    }
}
