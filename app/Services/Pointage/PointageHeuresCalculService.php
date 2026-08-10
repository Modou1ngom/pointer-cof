<?php

namespace App\Services\Pointage;

use App\Models\Pointage;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcul des durées travaillées à partir des heures effectives / réelles
 * (meta pointage), pas uniquement de clocked_at brut.
 */
final class PointageHeuresCalculService
{
    /**
     * Minutes d’une journée : HD − HA (gère le passage minuit).
     *
     * @return array{effective: int|null, reelle: int|null}
     */
    public function minutesJournee(?Pointage $arrivee, ?Pointage $depart): array
    {
        if ($arrivee === null || $depart === null) {
            return ['effective' => null, 'reelle' => null];
        }

        $day = $arrivee->clocked_at->copy()->startOfDay();

        return [
            'effective' => $this->diffMinutes(
                $this->carbonFromPunch($arrivee, 'effective', $day),
                $this->carbonFromPunch($depart, 'effective', $day),
            ),
            'reelle' => $this->diffMinutes(
                $this->carbonFromPunch($arrivee, 'reelle', $day),
                $this->carbonFromPunch($depart, 'reelle', $day),
            ),
        ];
    }

    /**
     * Agrège les minutes d’un utilisateur sur une période.
     *
     * @return array{effective: int, reelle: int, jours_complets: int}
     */
    public function minutesUserBetween(int $userId, Carbon $start, Carbon $end): array
    {
        $events = Pointage::query()
            ->where('user_id', $userId)
            ->whereBetween('clocked_at', [$start, $end])
            ->orderBy('clocked_at')
            ->get();

        return $this->minutesFromCollection($events);
    }

    /**
     * @param  Collection<int, Pointage>  $events
     * @return array{effective: int, reelle: int, jours_complets: int}
     */
    public function minutesFromCollection(Collection $events): array
    {
        $byDay = $events->groupBy(fn (Pointage $p) => $p->clocked_at->format('Y-m-d'));
        $eff = 0;
        $reel = 0;
        $jours = 0;

        foreach ($byDay as $items) {
            /** @var Collection<int, Pointage> $items */
            $sorted = $items->sortBy(fn (Pointage $p) => $p->clocked_at->timestamp)->values();
            $arrivee = $sorted->firstWhere('type', 'arrivee');
            $depart = $sorted
                ->filter(fn (Pointage $p) => $p->type === 'depart')
                ->sortByDesc(fn (Pointage $p) => $p->clocked_at->timestamp)
                ->first();

            $day = $this->minutesJournee($arrivee, $depart);
            if ($day['effective'] !== null) {
                $eff += $day['effective'];
                $jours++;
            }
            if ($day['reelle'] !== null) {
                $reel += $day['reelle'];
            }
        }

        return [
            'effective' => $eff,
            'reelle' => $reel,
            'jours_complets' => $jours,
        ];
    }

    public function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $sign = $minutes < 0 ? '-' : '';
        $m = abs($minutes);
        $h = intdiv($m, 60);
        $min = $m % 60;

        return $sign.$h.'h'.str_pad((string) $min, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Heures supplémentaires : max(0, heures_effectives − seuil × jours_complets).
     * Seuil = config pointage.seuil_heures_supplementaires_h_jour (défaut 8).
     *
     * @return array{minutes: int, label: string}
     */
    public function heuresSupplementaires(int $minutesEffective, int $joursComplets): array
    {
        $seuilJour = (float) config('pointage.seuil_heures_supplementaires_h_jour', 8);
        $base = (int) round($seuilJour * 60 * max(0, $joursComplets));
        $sup = max(0, $minutesEffective - $base);

        return [
            'minutes' => $sup,
            'label' => $sup > 0 ? '+'.$this->formatMinutes($sup) : '—',
        ];
    }

    private function carbonFromPunch(Pointage $p, string $kind, Carbon $day): Carbon
    {
        $meta = $p->meta ?? [];
        $raw = $kind === 'effective'
            ? ($meta['heure_effective'] ?? null)
            : ($meta['heure_reelle'] ?? null);

        if (is_string($raw) && trim($raw) !== '') {
            $parsed = $this->parseTimeToHm($raw);
            if ($parsed !== null) {
                return $day->copy()->setTime($parsed[0], $parsed[1], 0);
            }
        }

        if ($kind === 'effective' && is_string($meta['heure_effective_at'] ?? null)) {
            try {
                return Carbon::parse($meta['heure_effective_at']);
            } catch (\Throwable) {
                // fallback clocked_at
            }
        }

        return $p->clocked_at->copy();
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function parseTimeToHm(string $value): ?array
    {
        $clean = str_replace(['h', 'H'], ':', trim($value));
        if ($clean === '' || ! preg_match('/^\d/', $clean)) {
            return null;
        }
        // Formats : "8", "8:00", "08:00", "8:0"
        if (preg_match('/^(\d{1,2})(?::(\d{1,2}))?$/', $clean, $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $min = isset($m[2]) ? (int) $m[2] : 0;
        if ($h > 23 || $min > 59) {
            return null;
        }

        return [$h, $min];
    }

    private function diffMinutes(Carbon $start, Carbon $end): int
    {
        // Départ après minuit (ex. HA 22:00 / HD 01:00)
        if ($end->lt($start)) {
            $end = $end->copy()->addDay();
        }

        return (int) $start->diffInMinutes($end);
    }
}
