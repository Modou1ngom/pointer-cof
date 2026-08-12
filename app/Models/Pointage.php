<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pointage extends Model
{
    protected $fillable = [
        'user_id',
        'agence_id',
        'type',
        'clocked_at',
        'latitude',
        'longitude',
        'qr_verified',
        'biometric_ok',
        'statut',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'clocked_at' => 'datetime',
            'qr_verified' => 'boolean',
            'biometric_ok' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agence(): BelongsTo
    {
        return $this->belongsTo(Agence::class, 'agence_id');
    }

    /**
     * Pointage généré automatiquement (férié / déclaration RH), pas un scan réel.
     */
    public function isSynthetic(): bool
    {
        if ($this->statut === 'ferie_auto') {
            return true;
        }

        $meta = is_array($this->meta) ? $this->meta : [];
        if (($meta['auto_ferie'] ?? false) === true) {
            return true;
        }
        if (($meta['source'] ?? null) === 'declaration_rh') {
            return true;
        }
        if (($meta['requested_type'] ?? null) === 'auto') {
            return true;
        }

        return false;
    }

    public function heureAffichee(): string
    {
        $meta = $this->meta ?? [];
        if (is_string($meta['heure_effective'] ?? null) && $meta['heure_effective'] !== '') {
            return self::toFrenchTimeString((string) $meta['heure_effective']);
        }

        return $this->clocked_at?->format('H\hi') ?? '—';
    }

    public function heureReelleAffichee(): string
    {
        $meta = $this->meta ?? [];
        if (is_string($meta['heure_reelle'] ?? null) && $meta['heure_reelle'] !== '') {
            return self::toFrenchTimeString((string) $meta['heure_reelle']);
        }

        return $this->clocked_at?->format('H\hi') ?? '—';
    }

    /**
     * Convertit « 8:00 » / « 08:00 » / « 8 » / « 8h » en « 08h00 » (format heure français).
     */
    private static function toFrenchTimeString(string $hhmm): string
    {
        $clean = str_replace(['h', 'H'], ':', trim($hhmm));
        if ($clean === '' || ! preg_match('/^\d/', $clean)) {
            return $hhmm;
        }
        $parts = explode(':', $clean);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);

        return sprintf('%02dh%02d', $h, $m);
    }
}
