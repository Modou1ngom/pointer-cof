<?php

namespace App\Services\Pointage;

use App\Models\Attendance;
use App\Models\Pointage;
use App\Models\PointageDeclaration;
use App\Support\PointageDeclarationTypes;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Après validation RH : pose des pointages effectifs 08:00 / 17:00
 * pour que la journée ne soit plus traitée comme absence injustifiée.
 */
final class PointageDeclarationPresenceService
{
    public function appliquerApresValidationRh(PointageDeclaration $declaration): int
    {
        if (! in_array($declaration->type, PointageDeclarationTypes::TYPES_JUSTIFICATIFS_PRESENCE, true)) {
            return 0;
        }

        $userId = (int) $declaration->user_id;
        if ($userId <= 0) {
            return 0;
        }

        $start = $declaration->date_concernee?->copy()->startOfDay();
        if ($start === null) {
            return 0;
        }
        $end = $declaration->date_fin?->copy()->startOfDay() ?? $start->copy();
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $needArrivee = $this->needsArrivee($declaration);
        $needDepart = $this->needsDepart($declaration);

        $heureArrivee = $this->normalizeTime((string) config('pointage.heure_arrivee_ajustee', config('pointage.heure_arrivee', '08:00')));
        $heureDepart = $this->normalizeTime((string) config('pointage.heure_depart_ajustee', config('pointage.heure_depart', '17:00')));

        $agenceId = $this->resolveAgenceId($userId);
        $createdOrUpdated = 0;

        foreach (CarbonPeriod::create($start, $end) as $day) {
            /** @var Carbon $day */
            if ($needArrivee) {
                $createdOrUpdated += $this->upsertPunch(
                    $userId,
                    $agenceId,
                    $day,
                    'arrivee',
                    'checkin',
                    $heureArrivee,
                    $declaration,
                ) ? 1 : 0;
            }
            if ($needDepart) {
                $createdOrUpdated += $this->upsertPunch(
                    $userId,
                    $agenceId,
                    $day,
                    'depart',
                    'checkout',
                    $heureDepart,
                    $declaration,
                ) ? 1 : 0;
            }
        }

        return $createdOrUpdated;
    }

    private function needsArrivee(PointageDeclaration $d): bool
    {
        if ($d->type !== 'regularisation') {
            return true;
        }

        $motif = mb_strtolower((string) $d->motif);
        if (str_contains($motif, 'sortie') && ! str_contains($motif, 'entrée') && ! str_contains($motif, 'entree')) {
            return false;
        }
        if ($d->heure_fin && ! $d->heure_debut) {
            return false;
        }

        return true;
    }

    private function needsDepart(PointageDeclaration $d): bool
    {
        if ($d->type !== 'regularisation') {
            return true;
        }

        $motif = mb_strtolower((string) $d->motif);
        if ((str_contains($motif, 'entrée') || str_contains($motif, 'entree')) && ! str_contains($motif, 'sortie')) {
            return false;
        }
        if ($d->heure_debut && ! $d->heure_fin) {
            return false;
        }

        return true;
    }

    private function upsertPunch(
        int $userId,
        ?int $agenceId,
        Carbon $day,
        string $pointageType,
        string $attendanceType,
        string $heureEffective,
        PointageDeclaration $declaration,
    ): bool {
        $clockedAt = $day->copy()->setTimeFromTimeString($heureEffective.':00');
        $existing = Pointage::query()
            ->where('user_id', $userId)
            ->where('type', $pointageType)
            ->whereDate('clocked_at', $day->toDateString())
            ->orderBy('id')
            ->first();

        $metaBase = [
            'heure_reelle' => $existing
                ? (string) (($existing->meta['heure_reelle'] ?? null) ?: $existing->clocked_at?->format('H:i') ?: $heureEffective)
                : $heureEffective,
            'heure_effective' => $heureEffective,
            'heure_effective_at' => $clockedAt->toIso8601String(),
            'ajustement_applique' => true,
            'source' => 'declaration_rh',
            'declaration_id' => $declaration->id,
            'declaration_type' => $declaration->type,
        ];

        if ($existing) {
            $meta = array_merge($existing->meta ?? [], $metaBase);
            $existing->update([
                'statut' => 'normal',
                'meta' => $meta,
            ]);
        } else {
            Pointage::query()->create([
                'user_id' => $userId,
                'agence_id' => $agenceId,
                'type' => $pointageType,
                'clocked_at' => $clockedAt,
                'latitude' => null,
                'longitude' => null,
                'qr_verified' => false,
                'biometric_ok' => false,
                'statut' => 'normal',
                'meta' => $metaBase,
            ]);
        }

        $attExisting = Attendance::query()
            ->where('user_id', $userId)
            ->where('type', $attendanceType)
            ->whereDate('recorded_at', $day->toDateString())
            ->orderBy('id')
            ->first();

        if ($attExisting) {
            // Ne pas écraser une vraie heure réelle déjà pointée : on garde recorded_at,
            // le reporting RH lit surtout Pointage.meta.heure_effective.
        } else {
            Attendance::query()->create([
                'user_id' => $userId,
                'type' => $attendanceType,
                'qr_payload' => 'declaration:'.$declaration->id,
                'latitude' => null,
                'longitude' => null,
                'biometric_nonce' => null,
                'recorded_at' => $clockedAt,
            ]);
        }

        return true;
    }

    private function resolveAgenceId(int $userId): ?int
    {
        $last = Pointage::query()
            ->where('user_id', $userId)
            ->whereNotNull('agence_id')
            ->orderByDesc('clocked_at')
            ->value('agence_id');

        return $last !== null ? (int) $last : null;
    }

    private function normalizeTime(string $hhmm): string
    {
        $clean = str_replace(['h', 'H'], ':', trim($hhmm));
        $parts = explode(':', $clean);
        $h = (int) ($parts[0] ?? 8);
        $m = (int) ($parts[1] ?? 0);

        return sprintf('%02d:%02d', $h, $m);
    }
}
