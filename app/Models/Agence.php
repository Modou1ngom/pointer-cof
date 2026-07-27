<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Agence extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom',
        'code_agent',
        'description',
        'latitude',
        'longitude',
        'rayon_geofencing_metres',
        'pointage_qr_type',
        'pointage_qr_secret',
        'pointage_qr_activated_on',
        'pointage_qr_expires_on',
        'pointage_plage_debut',
        'pointage_plage_fin',
        'pointage_qr_enabled',
        'pointage_qr_enrolled_at',
        'pointage_kiosk_token',
        'actif',
        'is_virtual',
        'parent_agence_id',
        'kiosk_serial_number',
        'chef_agence_id',
        'filiale_id',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'is_virtual' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'rayon_geofencing_metres' => 'integer',
        'pointage_qr_activated_on' => 'date',
        'pointage_qr_expires_on' => 'date',
        'pointage_qr_enabled' => 'boolean',
        'pointage_qr_enrolled_at' => 'datetime',
    ];

    public function isVirtual(): bool
    {
        return (bool) $this->is_virtual;
    }

    public function parentAgence()
    {
        return $this->belongsTo(self::class, 'parent_agence_id');
    }

    public function virtualChildren()
    {
        return $this->hasMany(self::class, 'parent_agence_id');
    }

    /**
     * Agences ayant complété l’enrôlement « Génération QR Code » (module pointage).
     */
    public function scopeEnrolledForPointageQr($query)
    {
        return $query->whereNotNull('pointage_qr_enrolled_at');
    }

    public function isEnrolledForPointageQr(): bool
    {
        return $this->pointage_qr_enrolled_at !== null;
    }

    public function markEnrolledForPointageQr(): void
    {
        if ($this->pointage_qr_enrolled_at === null) {
            $this->pointage_qr_enrolled_at = now();
        }
        $this->ensureKioskToken(false);
    }

    /**
     * Jeton secret pour l’URL borne / tablette (/pointage/kiosk/{token}).
     */
    public function ensureKioskToken(bool $persist = true): string
    {
        if ($this->pointage_kiosk_token) {
            return $this->pointage_kiosk_token;
        }

        $this->pointage_kiosk_token = bin2hex(random_bytes(24));

        if ($persist && $this->exists) {
            $this->save();
        }

        return $this->pointage_kiosk_token;
    }

    public function regenerateKioskToken(): string
    {
        $this->pointage_kiosk_token = bin2hex(random_bytes(24));
        $this->save();

        return $this->pointage_kiosk_token;
    }

    public function kioskUrl(): ?string
    {
        $token = $this->pointage_kiosk_token;
        if (! $token) {
            return null;
        }

        return route('pointage.kiosk.show', ['token' => $token]);
    }

    public function pointages()
    {
        return $this->hasMany(Pointage::class, 'agence_id');
    }

    /**
     * Relation avec les profils (basée sur le nom de l'agence)
     */
    public function profils()
    {
        return Profil::where('site', $this->nom);
    }

    /**
     * Relation avec le chef d'agence
     */
    public function chefAgence()
    {
        return $this->belongsTo(Profil::class, 'chef_agence_id');
    }

    /**
     * Relation avec la filiale
     */
    public function filiale()
    {
        return $this->belongsTo(Filiale::class, 'filiale_id');
    }

    /**
     * Relation avec les utilisateurs (many-to-many).
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'agence_user', 'agence_id', 'user_id')
            ->withPivot('is_default')
            ->withTimestamps();
    }
}
