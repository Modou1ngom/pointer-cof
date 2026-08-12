<?php

namespace App\Services\Pointage;

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
     * }
     */
    public function computeEffectivePunch(Carbon $clockedAt, string $type, ?string $plage = null): array
    {
        $date = $clockedAt->copy()->startOfDay();
        $heureArrivee = (string) config('pointage.heure_arrivee', '08:00');
        $heureDepart = (string) config('pointage.heure_depart', '17:00');
        $heureArriveeAjustee = (string) config('pointage.heure_arrivee_ajustee', $heureArrivee);
        $heureDepartAjustee = (string) config('pointage.heure_depart_ajustee', $heureDepart);
        $toleranceMinutes = (int) config('pointage.tolerance_minutes', 10);

        $limiteRetard = $date->copy()->setTimeFromTimeString($heureArrivee)->addMinutes($toleranceMinutes);
        $limiteDepart = $date->copy()->setTimeFromTimeString($heureDepart);

        $statut = 'normal';
        $ajustementApplique = false;
        $heureEffective = $clockedAt->format('H:i');

        if ($type === 'arrivee') {
            // Avant / dans la tolérance de 08:00 → effective = heure ajustée ; après → réelle.
            if ($clockedAt->greaterThan($limiteRetard)) {
                $statut = 'retard';
                $heureEffective = $clockedAt->format('H:i');
            } else {
                $ajustementApplique = true;
                $heureEffective = $this->formatTimeShort($heureArriveeAjustee);
            }
        } else {
            // Symétrique à l’entrée (réf. 17:00) :
            // avant 17:00 → effective = réelle ; à partir de 17:00 → effective = heure ajustée.
            if ($clockedAt->lt($limiteDepart)) {
                $heureEffective = $clockedAt->format('H:i');
            } else {
                $ajustementApplique = true;
                $heureEffective = $this->formatTimeShort($heureDepartAjustee);
            }
        }

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
