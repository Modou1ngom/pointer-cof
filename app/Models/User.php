<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'full_name',
        'email',
        'matricule',
        'avatar_url',
        'password',
        'is_active',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /** @var array<string, true>|null */
    private ?array $cachedRoleSlugSet = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Relation avec le profil (via email)
     */
    public function profil()
    {
        return $this->hasOne(Profil::class, 'email', 'email');
    }

    public function pointages()
    {
        return $this->hasMany(Pointage::class);
    }

    public function pointageDeclarations()
    {
        return $this->hasMany(PointageDeclaration::class);
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function pointageApiNotifications()
    {
        return $this->hasMany(PointageApiNotification::class);
    }

    /**
     * Fiche collaborateur liée au compte : même logique que {@see profil()}, avec correspondance
     * d’e-mail insensible à la casse et aux espaces (évite les écarts import / compte).
     *
     * @return $this
     */
    public function profilCollaborateurAssocie(): self
    {
        $this->loadMissing('profil');
        if ($this->profil !== null) {
            return $this;
        }

        $email = strtolower(trim((string) $this->email));
        if ($email === '') {
            return $this;
        }

        $found = Profil::query()
            ->whereNotNull('email')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if ($found !== null) {
            $this->setRelation('profil', $found);
        }

        return $this;
    }

    /**
     * Relation avec les rôles (many-to-many)
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id');
    }

    /**
     * Relation avec les filiales/environnements (many-to-many)
     */
    public function filiales()
    {
        return $this->belongsToMany(Filiale::class, 'user_filiale', 'user_id', 'filiale_id');
    }

    /**
     * Relation avec les agences (many-to-many).
     */
    public function agences()
    {
        return $this->belongsToMany(Agence::class, 'agence_user', 'user_id', 'agence_id')
            ->withPivot([
                'is_default',
                'date_debut_autorisation',
                'date_fin_autorisation',
                'statut_agence',
                'niveau_acces',
            ])
            ->withTimestamps();
    }

    public function pointageAffectationSetting()
    {
        return $this->hasOne(PointageUserAffectationSetting::class);
    }

    /**
     * Retourne l'agence domiciliaire (par défaut) de l'utilisateur.
     */
    public function agenceDomiciliaire(): ?Agence
    {
        return $this->agences->firstWhere('pivot.is_default', true);
    }

    /**
     * Vérifie si l'utilisateur a un rôle spécifique
     * Vérifie d'abord les rôles de l'utilisateur, puis ceux du profil
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->roleSlugSet()[$roleSlug] ?? false;
    }

    /**
     * Vérifie si l'utilisateur a au moins un des rôles spécifiés
     * Vérifie d'abord les rôles de l'utilisateur, puis ceux du profil
     */
    public function hasAnyRole(array $roleSlugs): bool
    {
        $set = $this->roleSlugSet();
        foreach ($roleSlugs as $slug) {
            if ($set[$slug] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function roleSlugSet(): array
    {
        if (isset($this->cachedRoleSlugSet)) {
            return $this->cachedRoleSlugSet;
        }

        if ($this->relationLoaded('roles') === false) {
            $this->load('roles');
        }

        $slugs = $this->roles->pluck('slug')->filter()->all();

        $this->profilCollaborateurAssocie();
        if ($this->profil) {
            if ($this->profil->relationLoaded('roles') === false) {
                $this->profil->load('roles');
            }
            $slugs = array_merge($slugs, $this->profil->roles->pluck('slug')->filter()->all());
        }

        return $this->cachedRoleSlugSet = array_fill_keys(array_values(array_unique($slugs)), true);
    }

    /**
     * Vérifie si l'utilisateur est admin
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin') || $this->isSuperAdmin();
    }

    /**
     * Vérifie si l'utilisateur est super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Filiales du périmètre (pivot + filiale du profil).
     * Un super admin n’échappe pas à ce périmètre : il est super admin
     * de sa (ses) filiale(s), pas des autres.
     *
     * @return list<int>
     */
    public function filialeIdsDuPerimetre(): array
    {
        $this->loadMissing('profil');
        $ids = $this->filiales()->pluck('filiales.id')->map(fn ($id) => (int) $id)->all();
        $profilFilialeId = $this->profil?->filiale_id;
        if ($profilFilialeId && ! in_array((int) $profilFilialeId, $ids, true)) {
            $ids[] = (int) $profilFilialeId;
        }

        return array_values(array_unique($ids));
    }

    public function appartientAuPerimetreFiliale(?int $filialeId): bool
    {
        if ($filialeId === null) {
            return false;
        }

        return in_array($filialeId, $this->filialeIdsDuPerimetre(), true);
    }

    /**
     * Vérifie si l'utilisateur est métier
     */
    public function isMetier(): bool
    {
        return $this->hasRole('metier');
    }

    /**
     * Vérifie si l'utilisateur est contrôle
     */
    public function isControle(): bool
    {
        return $this->hasRole('controle');
    }

    /**
     * Vérifie si l'utilisateur est RH
     */
    public function isRh(): bool
    {
        return $this->hasRole('rh');
    }

    public function isFinance(): bool
    {
        return $this->hasRole('finance');
    }

    public function isMd(): bool
    {
        return $this->hasRole('md');
    }

    /**
     * Vérifie si l'utilisateur est conformité
     */
    public function isConformite(): bool
    {
        return $this->hasRole('conformite');
    }

    /**
     * Vérifie si l'utilisateur est exécuteur IT (basé sur le profil)
     * Note: "informatique" est automatiquement normalisé en "IT" via les accessors du modèle Profil
     */
    public function isExecuteurIt(): bool
    {
        // Vérifier d'abord les rôles pour compatibilité
        if ($this->hasRole('executeur_it') || $this->hasRole('it')) {
            return true;
        }

        // Recharger le profil si nécessaire
        if (! $this->relationLoaded('profil')) {
            $this->load('profil');
        }

        if (! $this->profil) {
            return false;
        }

        $profil = $this->profil;

        // Vérifier si le département contient "IT" ou "informatique"
        // On vérifie la valeur brute directement pour éviter les problèmes avec les accessors
        $departement = $profil->getRawOriginal('departement') ?? $profil->departement;
        if ($departement) {
            $departementLower = strtolower($departement);
            // Vérifier "it" comme mot entier (pas dans "capital", "spirit", etc.)
            if (preg_match('/\b(it|informatique|technique)\b/i', $departementLower)) {
                return true;
            }
        }

        // Vérifier si la fonction contient "IT" ou "informatique"
        $fonction = $profil->getRawOriginal('fonction') ?? $profil->fonction;
        if ($fonction) {
            $fonctionLower = strtolower($fonction);
            // Vérifier "it" comme mot entier (pas dans "capital", "spirit", etc.)
            if (preg_match('/\b(it|informatique|technique)\b/i', $fonctionLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie si l'utilisateur est Head IT
     */
    public function isHeadIt(): bool
    {
        return $this->hasRole('head_it') || $this->hasRole('chef_it');
    }

    /**
     * Vérifie si l'utilisateur est Audit
     */
    public function isAudit(): bool
    {
        return $this->hasRole('audit') || $this->hasRole('direction_audit');
    }

    /**
     * Vérifie si l'utilisateur est responsable d'un département
     */
    public function isResponsableDepartement(): bool
    {
        if (! $this->profil) {
            return false;
        }

        return \App\Models\Departement::where('responsable_departement_id', $this->profil->id)
            ->where('actif', true)
            ->exists();
    }

    /**
     * Récupère le département dont l'utilisateur est responsable
     */
    public function getDepartementResponsable()
    {
        if (! $this->profil) {
            return null;
        }

        return \App\Models\Departement::where('responsable_departement_id', $this->profil->id)
            ->where('actif', true)
            ->first();
    }

    /**
     * Récupère tous les rôles de l'utilisateur
     */
    public function getRoles(): \Illuminate\Support\Collection
    {
        return $this->roles;
    }
}
