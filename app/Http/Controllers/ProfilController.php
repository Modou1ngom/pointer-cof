<?php

namespace App\Http\Controllers;

use App\Exports\ProfilsExport;
use App\Models\Agence;
use App\Models\Departement;
use App\Models\Filiale;
use App\Models\Profil;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ProfilController extends Controller
{
    /**
     * Applique le filtrage par filiale selon le rôle de l'utilisateur
     */
    private function applyFilialeFilter($query, $user)
    {
        $isSuperAdmin = $user && $user->isSuperAdmin();
        $isAdmin = $user && $user->isAdmin();
        $isRh = $user && $user->isRh();

        // Super admin voit tous les profils
        if ($isSuperAdmin) {
            return $query;
        }
        // Admin normal et RH voient uniquement les profils de leurs filiales assignées
        elseif (($isAdmin || $isRh) && $user) {
            $userFilialesIds = $user->filiales()->get()->pluck('id')->toArray();
            if (! empty($userFilialesIds)) {
                return $query->whereIn('filiale_id', $userFilialesIds);
            } else {
                // Si l'admin/RH n'a aucune filiale assignée, il ne voit rien
                return $query->where('id', 0);
            }
        }

        return $query;
    }

    /**
     * Applique le filtrage des agences par filiale selon le rôle de l'utilisateur
     */
    private function filterAgencesByFiliale($user)
    {
        $isSuperAdmin = $user && $user->isSuperAdmin();
        $isAdmin = $user && $user->isAdmin();
        $isRh = $user && $user->isRh();

        $query = Agence::where('actif', true);

        // Super admin voit toutes les agences
        if ($isSuperAdmin) {
            return $query;
        }
        // Admin normal et RH voient uniquement les agences de leurs filiales assignées
        elseif (($isAdmin || $isRh) && $user) {
            $userFilialesIds = $user->filiales()->get()->pluck('id')->toArray();
            if (! empty($userFilialesIds)) {
                return $query->whereIn('filiale_id', $userFilialesIds);
            } else {
                // Si l'admin/RH n'a aucune filiale assignée, il ne voit rien
                return $query->where('id', 0);
            }
        }
        // Les autres utilisateurs voient les agences de leurs filiales assignées ou de leur profil
        elseif ($user) {
            $userFilialesIds = $user->filiales()->get()->pluck('id')->toArray();
            $userProfil = $user->profil;

            // Si l'utilisateur a un profil avec une filiale_id, l'ajouter aussi
            if ($userProfil && $userProfil->filiale_id) {
                if (! in_array($userProfil->filiale_id, $userFilialesIds)) {
                    $userFilialesIds[] = $userProfil->filiale_id;
                }
            }

            if (! empty($userFilialesIds)) {
                return $query->whereIn('filiale_id', $userFilialesIds);
            } else {
                // Si l'utilisateur n'a aucune filiale assignée, il ne voit rien
                return $query->where('id', 0);
            }
        }

        return $query->where('id', 0);
    }

    /**
     * Vérifie si l'utilisateur peut accéder à un profil donné
     */
    private function canAccessProfil(Profil $profil, $user)
    {
        if (! $user) {
            return false;
        }

        $isSuperAdmin = $user->isSuperAdmin();

        // Super admin peut accéder à tous les profils
        if ($isSuperAdmin) {
            return true;
        }

        $isAdmin = $user->isAdmin();
        $isRh = $user->isRh();

        // Admin normal et RH peuvent accéder uniquement aux profils de leurs filiales assignées
        if (($isAdmin || $isRh)) {
            $userFilialesIds = $user->filiales()->get()->pluck('id')->toArray();
            if (! empty($userFilialesIds) && $profil->filiale_id) {
                return in_array($profil->filiale_id, $userFilialesIds);
            }

            return false;
        }

        // Pour les autres utilisateurs, vérifier leurs filiales assignées ou leur profil
        $userFilialesIds = $user->filiales()->get()->pluck('id')->toArray();
        $userProfil = $user->profil;

        // Si l'utilisateur a un profil avec une filiale_id, l'ajouter aussi
        if ($userProfil && $userProfil->filiale_id) {
            if (! in_array($userProfil->filiale_id, $userFilialesIds)) {
                $userFilialesIds[] = $userProfil->filiale_id;
            }
        }

        // Si l'utilisateur a des filiales assignées, vérifier si le profil appartient à une de ces filiales
        if (! empty($userFilialesIds) && $profil->filiale_id) {
            return in_array($profil->filiale_id, $userFilialesIds);
        }

        // Sinon, vérifier s'ils peuvent voir leur propre profil ou leurs subordonnés
        if ($userProfil) {
            return $profil->id === $userProfil->id || $profil->n_plus_1_id === $userProfil->id;
        }

        return false;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $perPage = (int) $request->get('per_page', 5);

        // Construire la requête de base
        $query = Profil::query();

        // Distinguer super admin, admin normal et RH
        $isSuperAdmin = $user && $user->isSuperAdmin();
        $isAdmin = $user && $user->isAdmin();
        $isRh = $user && $user->isRh();

        // Super admin voit tous les profils
        if ($isSuperAdmin) {
            // Pas de restriction pour le super admin
        }
        // Admin normal voit uniquement les profils de ses filiales assignées
        elseif ($isAdmin && $user) {
            $userFilialesIds = $user->filiales()->get()->pluck('id')->toArray();

            // Si l'admin a un profil avec une filiale_id, l'ajouter aussi
            $userProfil = $user->profil;
            if ($userProfil && $userProfil->filiale_id) {
                if (! in_array($userProfil->filiale_id, $userFilialesIds)) {
                    $userFilialesIds[] = $userProfil->filiale_id;
                }
            }

            if (! empty($userFilialesIds)) {
                $query->whereIn('filiale_id', $userFilialesIds);
            } else {
                // Si l'admin n'a aucune filiale assignée, il ne voit rien
                $query->where('id', 0);
            }
        }
        // RH voit uniquement les profils de ses filiales assignées (si applicable)
        elseif ($isRh && $user) {
            $userFilialesIds = $user->filiales()->get()->pluck('id')->toArray();

            // Si le RH a un profil avec une filiale_id, l'ajouter aussi
            $userProfil = $user->profil;
            if ($userProfil && $userProfil->filiale_id) {
                if (! in_array($userProfil->filiale_id, $userFilialesIds)) {
                    $userFilialesIds[] = $userProfil->filiale_id;
                }
            }

            if (! empty($userFilialesIds)) {
                $query->whereIn('filiale_id', $userFilialesIds);
            } else {
                // Si le RH n'a aucune filiale assignée, il ne voit rien
                $query->where('id', 0);
            }
        }
        // Les autres utilisateurs
        else {
            $profil = $user?->profil;

            // Vérifier d'abord si l'utilisateur a des filiales assignées
            $userFilialesIds = $user ? $user->filiales()->get()->pluck('id')->toArray() : [];

            // Si l'utilisateur a un profil avec une filiale_id, l'ajouter aussi
            if ($profil && $profil->filiale_id) {
                if (! in_array($profil->filiale_id, $userFilialesIds)) {
                    $userFilialesIds[] = $profil->filiale_id;
                }
            }

            if (! empty($userFilialesIds)) {
                // L'utilisateur voit les profils de ses filiales assignées
                $query->whereIn('filiale_id', $userFilialesIds);
            } elseif ($profil) {
                // Sinon, il voit uniquement son propre profil et ses subordonnés
                $query->where(function ($q) use ($profil) {
                    $q->where('id', $profil->id)
                        ->orWhere('n_plus_1_id', $profil->id);
                });
            } else {
                $query->where('id', 0);
            }
        }

        // Filtre par statut
        if ($request->has('statut') && $request->statut !== '') {
            $query->where('statut', $request->statut);
        }

        // Filtre par département
        if ($request->has('departement') && $request->departement) {
            $departement = Departement::find($request->departement);
            if ($departement) {
                $query->where('departement', $departement->nom);
            }
        }

        // Filtre par fonction
        if ($request->has('fonction') && $request->fonction) {
            $query->where('fonction', 'like', "%{$request->fonction}%");
        }

        // Filtre par site/agence
        if ($request->has('site') && $request->site) {
            $agence = Agence::find($request->site);
            if ($agence) {
                $query->where('site', $agence->nom);
            }
        }

        // Filtre par type de contrat
        if ($request->has('type_contrat') && $request->type_contrat) {
            $query->where('type_contrat', $request->type_contrat);
        }

        $this->applyProfilCompteFilters($query, $request);

        // Filtre par recherche (nom, prénom, matricule, email)
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('matricule', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $profils = $query->orderBy('nom')
            ->orderBy('prenom')
            ->paginate($perPage);

        $profils = $this->attachComptesUtilisateurs($profils);

        // Récupérer les données pour les filtres
        $departements = Departement::where('actif', true)->orderBy('nom')->get(['id', 'nom']);
        $agencesQuery = $this->filterAgencesByFiliale($user);
        $agences = $agencesQuery->orderBy('nom')->get(['id', 'nom']);
        $roles = Role::where('actif', true)->orderBy('nom')->get(['id', 'nom']);

        return Inertia::render('profils/Index', [
            'profils' => $profils,
            'departements' => $departements,
            'agences' => $agences,
            'roles' => $roles,
            'canManageComptes' => (bool) ($user && ($isSuperAdmin || ($isAdmin && ! $isRh))),
        ]);
    }

    /**
     * Filtres liés au compte utilisateur associé (même e-mail que le profil).
     */
    private function applyProfilCompteFilters($query, Request $request): void
    {
        $emailMatch = function ($sub): void {
            $sub->selectRaw('1')
                ->from('users')
                ->whereNotNull('profiles.email')
                ->where('profiles.email', '!=', '')
                ->whereRaw('LOWER(TRIM(users.email)) = LOWER(TRIM(profiles.email))');
        };

        if ($request->filled('compte')) {
            if ($request->compte === 'avec') {
                $query->whereExists($emailMatch);
            } elseif ($request->compte === 'sans') {
                $query->whereNotExists($emailMatch);
            }
        }

        if ($request->has('activation') && $request->activation !== '') {
            $active = filter_var($request->activation, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active !== null) {
                $query->whereExists(function ($q) use ($active): void {
                    $q->selectRaw('1')
                        ->from('users')
                        ->whereRaw('LOWER(TRIM(users.email)) = LOWER(TRIM(profiles.email))')
                        ->where('users.is_active', $active);
                });
            }
        }

        if ($request->filled('role')) {
            $roleId = (int) $request->role;
            $query->whereExists(function ($q) use ($roleId): void {
                $q->selectRaw('1')
                    ->from('users')
                    ->join('user_role', 'users.id', '=', 'user_role.user_id')
                    ->whereRaw('LOWER(TRIM(users.email)) = LOWER(TRIM(profiles.email))')
                    ->where('user_role.role_id', $roleId);
            });
        }
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator  $profils
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    private function findCompteForProfil(Profil $profil): ?User
    {
        $email = mb_strtolower(trim((string) ($profil->email ?? '')));
        if ($email !== '') {
            $byEmail = User::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->first();
            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        $matricule = trim((string) ($profil->matricule ?? ''));
        if ($matricule !== '') {
            return User::query()->where('matricule', $matricule)->first();
        }

        return null;
    }

    /**
     * Crée ou met à jour le compte utilisateur lié au profil (même e-mail / matricule).
     */
    private function syncCompteForProfil(
        Profil $profil,
        ?string $password = null,
        ?bool $isActive = null,
        ?bool $mustChangePassword = null,
        bool $createIfMissing = false,
        ?User $knownUser = null
    ): ?User {
        $email = mb_strtolower(trim((string) ($profil->email ?? '')));
        $user = $knownUser ?? $this->findCompteForProfil($profil);

        if ($user === null && ! $createIfMissing) {
            return null;
        }

        if ($user === null && $email === '') {
            return null;
        }

        $displayName = trim($profil->prenom.' '.$profil->nom);

        if ($user === null) {
            $user = User::query()->create([
                'name' => $displayName !== '' ? $displayName : $email,
                'email' => $email,
                'matricule' => $profil->matricule,
                'password' => Hash::make($password ?? Str::random(32)),
                'must_change_password' => $mustChangePassword ?? true,
                'is_active' => $isActive ?? ($profil->statut === 'actif'),
            ]);
        } else {
            $updates = [
                'name' => $displayName !== '' ? $displayName : $user->name,
                'matricule' => $profil->matricule,
            ];
            if ($email !== '') {
                $updates['email'] = $email;
            }
            if ($isActive !== null) {
                $updates['is_active'] = $isActive;
            }
            if ($mustChangePassword !== null) {
                $updates['must_change_password'] = $mustChangePassword;
            }
            if ($password !== null && $password !== '') {
                $updates['password'] = Hash::make($password);
            }
            $user->update($updates);
        }

        if ($profil->filiale_id) {
            $user->filiales()->syncWithoutDetaching([(int) $profil->filiale_id]);
        }

        if (! empty($profil->site)) {
            $agence = Agence::query()->where('nom', $profil->site)->where('actif', true)->first();
            if ($agence !== null) {
                $user->agences()->sync([
                    $agence->id => ['is_default' => true],
                ]);
            }
        }

        return $user;
    }

    private function attachComptesUtilisateurs($profils)
    {
        $collection = $profils->getCollection();
        $emails = $collection
            ->pluck('email')
            ->filter(fn ($e) => is_string($e) && trim($e) !== '')
            ->map(fn ($e) => mb_strtolower(trim($e)))
            ->unique()
            ->values();

        $matricules = $collection
            ->pluck('matricule')
            ->filter(fn ($m) => is_string($m) && trim($m) !== '')
            ->map(fn ($m) => trim($m))
            ->unique()
            ->values();

        $usersByEmail = collect();
        $usersByMatricule = collect();

        if ($emails->isNotEmpty() || $matricules->isNotEmpty()) {
            $users = User::query()
                ->with(['roles:id,nom', 'agences:id,nom'])
                ->where(function ($q) use ($emails, $matricules) {
                    if ($emails->isNotEmpty()) {
                        $q->where(function ($eq) use ($emails) {
                            foreach ($emails as $email) {
                                $eq->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
                            }
                        });
                    }
                    if ($matricules->isNotEmpty()) {
                        $q->orWhereIn('matricule', $matricules->all());
                    }
                })
                ->get();

            $usersByEmail = $users
                ->filter(fn (User $u) => trim((string) $u->email) !== '')
                ->keyBy(fn (User $u) => mb_strtolower(trim((string) $u->email)));

            $usersByMatricule = $users
                ->filter(fn (User $u) => trim((string) $u->matricule) !== '')
                ->keyBy(fn (User $u) => trim((string) $u->matricule));
        }

        return $profils->through(function (Profil $profil) use ($usersByEmail, $usersByMatricule) {
            $payload = $profil->toArray();
            $email = mb_strtolower(trim((string) ($profil->email ?? '')));
            $user = $email !== '' ? $usersByEmail->get($email) : null;
            if ($user === null && trim((string) ($profil->matricule ?? '')) !== '') {
                $user = $usersByMatricule->get(trim((string) $profil->matricule));
            }

            if ($user !== null) {
                $defaultAgence = $user->agences->first(fn ($a) => (bool) ($a->pivot->is_default ?? false))
                    ?? $user->agences->first();
                $payload['compte'] = [
                    'id' => $user->id,
                    'is_active' => (bool) $user->is_active,
                    'roles_label' => $user->roles->pluck('nom')->join(', ') ?: '—',
                    'agence_label' => $defaultAgence?->nom ?? ($profil->site ?: '—'),
                ];
            } else {
                $payload['compte'] = null;
            }

            return $payload;
        });
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return redirect()->route('pointage.rh.employes')
            ->with('info', 'La création de profil et de compte utilisateur se fait depuis le module Pointage.');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        try {
            $validated = $request->validate([
                'nom' => 'required|string|max:255',
                'prenom' => 'required|string|max:255',
                'fonction' => 'nullable|string',
                'departement' => 'nullable|string',
                'email' => 'nullable|email|unique:profiles,email',
                'telephone' => ['nullable', 'string', 'max:20', 'regex:/^(\\+221|00221|221)?[0-9]{9}$/'],
                'site' => 'nullable|string|max:100',
                'filiale_id' => 'nullable|integer|exists:filiales,id',
                'type_contrat' => 'nullable|in:CDI,CDD,Stagiaire,Autre',
                'statut' => 'nullable|in:actif,inactif',
                'n_plus_1_id' => 'nullable|exists:profiles,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }

        // Générer automatiquement le matricule
        $validated['matricule'] = Profil::generateMatricule();

        // Calculer automatiquement N+2 : le N+1 du N+1
        $nPlus2Id = null;
        if (! empty($validated['n_plus_1_id'])) {
            $nPlus1 = Profil::find($validated['n_plus_1_id']);
            // Ne pas permettre que le N+2 soit le même que le N+1 (éviter les boucles)
            if ($nPlus1 && $nPlus1->n_plus_1_id && $nPlus1->n_plus_1_id != $validated['n_plus_1_id']) {
                $nPlus2Id = $nPlus1->n_plus_1_id;
            }
        }

        // Déterminer la filiale à assigner
        $filialeId = $validated['filiale_id'] ?? null;

        // Si filiale_id n'est pas fourni, essayer de le déduire
        if (! $filialeId) {
            // 1. Essayer depuis le site/agence sélectionné
            if (! empty($validated['site'])) {
                $agence = Agence::where('nom', $validated['site'])->first();
                if ($agence && $agence->filiale_id) {
                    $filialeId = $agence->filiale_id;
                }
            }

            // 2. Pour les admins et RH, utiliser leur filiale assignée
            $isSuperAdmin = $user && $user->isSuperAdmin();
            $isAdmin = $user && $user->isAdmin();
            $isRh = $user && $user->isRh();

            if (! $filialeId && ($isAdmin || $isRh) && ! $isSuperAdmin) {
                $userFiliales = $user->filiales()->get();
                if ($userFiliales->count() > 0) {
                    // Prendre la première filiale assignée
                    $filialeId = $userFiliales->first()->id;
                } elseif ($user->profil && $user->profil->filiale_id) {
                    // Sinon, utiliser la filiale du profil
                    $filialeId = $user->profil->filiale_id;
                }
            }

            // 3. Pour les autres utilisateurs, utiliser la filiale de leur profil
            if (! $filialeId && $user && $user->profil && $user->profil->filiale_id) {
                $filialeId = $user->profil->filiale_id;
            }
        }

        $data = [
            'nom' => $validated['nom'],
            'prenom' => $validated['prenom'],
            'matricule' => $validated['matricule'],
            'fonction' => $validated['fonction'] ?? null,
            'departement' => $validated['departement'] ?? null,
            'email' => $validated['email'] ?? null,
            'telephone' => $validated['telephone'] ?? null,
            'site' => $validated['site'] ?? null,
            'filiale_id' => $filialeId,
            'type_contrat' => $validated['type_contrat'] ?? 'CDI',
            'statut' => $validated['statut'] ?? 'actif',
            'n_plus_1_id' => $validated['n_plus_1_id'] ?? null,
            'n_plus_2_id' => $nPlus2Id,
        ];

        Profil::create($data);

        return redirect()->route('profils.index')
            ->with('success', 'Profil créé avec succès !');
    }

    /**
     * Display the specified resource.
     */
    public function show(Profil $profil)
    {
        $user = Auth::user();

        // Vérifier l'accès : super admin peut voir tout, sinon vérifier les filiales
        if (! $this->canAccessProfil($profil, $user)) {
            abort(403, 'Vous n\'avez pas accès à ce profil.');
        }

        $profil->load([
            'nPlus1:id,nom,prenom,matricule',
            'nPlus2:id,nom,prenom,matricule',
            'subordonnes:id,nom,prenom,matricule',
        ]);

        // Préparer les données avec les relations en snake_case pour le frontend
        $profilData = $profil->toArray();
        $profilData['n_plus_1'] = $profil->nPlus1 ? $profil->nPlus1->only(['id', 'nom', 'prenom', 'matricule']) : null;
        $profilData['n_plus_2'] = $profil->nPlus2 ? $profil->nPlus2->only(['id', 'nom', 'prenom', 'matricule']) : null;
        $profilData['subordonnes'] = $profil->subordonnes->map(function ($sub) {
            return $sub->only(['id', 'nom', 'prenom', 'matricule']);
        })->toArray();

        return Inertia::render('profils/Show', [
            'profil' => $profilData,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Profil $profil)
    {
        $user = Auth::user();

        // Vérifier l'accès : super admin peut voir tout, sinon vérifier les filiales
        if (! $this->canAccessProfil($profil, $user)) {
            abort(403, 'Vous n\'avez pas accès à ce profil.');
        }

        $profilsQuery = Profil::where('id', '!=', $profil->id);
        $profilsQuery = $this->applyFilialeFilter($profilsQuery, $user);
        $profils = $profilsQuery->orderBy('nom')
            ->get(['id', 'nom', 'prenom', 'matricule']);
        $departements = Departement::where('actif', true)
            ->with('responsable:id,nom,prenom,matricule')
            ->orderBy('nom')
            ->get(['id', 'nom', 'responsable_departement_id']);
        $agencesQuery = $this->filterAgencesByFiliale($user);
        $agences = $agencesQuery->orderBy('nom')->get(['id', 'nom', 'filiale_id']);
        $filiales = Filiale::where('actif', true)->orderBy('nom')->get(['id', 'nom']);
        $compteUser = $this->findCompteForProfil($profil);
        if ($compteUser !== null) {
            $compteUser->load(['roles:id,nom']);
        }

        $isSuperAdmin = $user && $user->isSuperAdmin();
        $isAdmin = $user && $user->isAdmin();
        $isRh = $user && $user->isRh();
        $canManageComptes = (bool) ($isSuperAdmin || ($isAdmin && ! $isRh));

        return Inertia::render('profils/Edit', [
            'profil' => $profil,
            'profils' => $profils,
            'departements' => $departements,
            'agences' => $agences,
            'filiales' => $filiales,
            'compte' => $compteUser ? [
                'id' => $compteUser->id,
                'is_active' => (bool) $compteUser->is_active,
                'must_change_password' => (bool) $compteUser->must_change_password,
                'role_ids' => $compteUser->roles->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ] : null,
            'roles' => $canManageComptes
                ? Role::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom', 'slug'])
                : [],
            'canManageComptes' => $canManageComptes,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Profil $profil)
    {
        $user = Auth::user();

        // Vérifier l'accès : super admin peut modifier tout, sinon vérifier les filiales
        if (! $this->canAccessProfil($profil, $user)) {
            abort(403, 'Vous n\'avez pas accès à ce profil.');
        }

        $canManageComptes = $user && ($user->isSuperAdmin() || ($user->isAdmin() && ! $user->isRh()));

        $rules = [
            'nom' => 'sometimes|required|string|max:255',
            'prenom' => 'sometimes|required|string|max:255',
            'matricule' => 'sometimes|required|string|max:50|unique:profiles,matricule,'.$profil->id,
            'fonction' => 'nullable|string',
            'departement' => 'nullable|string',
            'email' => 'nullable|email|unique:profiles,email,'.$profil->id,
            'telephone' => ['nullable', 'string', 'max:20', 'regex:/^(\\+221|00221|221)?[0-9]{9}$/'],
            'site' => 'nullable|string|max:100',
            'type_contrat' => 'nullable|in:CDI,CDD,Stagiaire,Autre',
            'statut' => 'nullable|in:actif,inactif',
            'n_plus_1_id' => 'nullable|exists:profiles,id',
        ];

        if ($canManageComptes) {
            $rules['create_compte'] = 'nullable|boolean';
            $rules['compte_is_active'] = 'nullable|boolean';
            $rules['compte_must_change_password'] = 'nullable|boolean';
            $rules['compte_password'] = 'nullable|string|min:8|confirmed';
            $rules['compte_role_ids'] = 'nullable|array';
            $rules['compte_role_ids.*'] = 'integer|exists:roles,id';
        }

        $validated = $request->validate($rules);

        // Calculer automatiquement N+2 : le N+1 du N+1
        $nPlus2Id = null;
        if (! empty($validated['n_plus_1_id'])) {
            // Ne pas permettre qu'un profil soit son propre N+1
            if ($validated['n_plus_1_id'] != $profil->id) {
                $nPlus1 = Profil::find($validated['n_plus_1_id']);
                // Ne pas permettre que le N+2 soit le même que le N+1
                if ($nPlus1 && $nPlus1->n_plus_1_id && $nPlus1->n_plus_1_id != $validated['n_plus_1_id']) {
                    $nPlus2Id = $nPlus1->n_plus_1_id;
                }
            }
        }

        $validated['n_plus_2_id'] = $nPlus2Id;

        // Vérifier si le N+1 a changé
        $nPlus1Changed = isset($validated['n_plus_1_id']) && $profil->n_plus_1_id != $validated['n_plus_1_id'];

        $compteFields = ['create_compte', 'compte_is_active', 'compte_must_change_password', 'compte_password', 'compte_password_confirmation', 'compte_role_ids'];
        $profilData = collect($validated)->except($compteFields)->all();

        // Retrouver le compte AVANT la mise à jour du profil (email/matricule peuvent changer).
        $existingCompte = $canManageComptes ? $this->findCompteForProfil($profil) : null;

        $profil->update($profilData);
        $profil->refresh();

        if ($canManageComptes) {
            $createCompte = $request->boolean('create_compte');
            $compteUser = $existingCompte;

            if ($createCompte || $existingCompte !== null) {
                $password = $request->filled('compte_password') ? (string) $request->input('compte_password') : null;
                if ($createCompte && $existingCompte === null && empty($password)) {
                    return back()->withErrors([
                        'compte_password' => 'Un mot de passe est requis pour créer le compte utilisateur.',
                    ])->withInput();
                }

                $synced = $this->syncCompteForProfil(
                    $profil,
                    $password,
                    $request->exists('compte_is_active') ? $request->boolean('compte_is_active') : null,
                    $request->exists('compte_must_change_password') ? $request->boolean('compte_must_change_password') : null,
                    $createCompte && $existingCompte === null,
                    $existingCompte
                );

                if ($synced !== null) {
                    $compteUser = $synced;
                }
            }

            // exists() (pas has()) : un tableau vide doit aussi synchroniser (retrait de tous les rôles).
            if ($compteUser !== null && $request->exists('compte_role_ids')) {
                $roleIds = array_values(array_unique(array_map(
                    'intval',
                    (array) ($validated['compte_role_ids'] ?? $request->input('compte_role_ids', []))
                )));
                $roleIds = array_values(array_filter($roleIds, fn (int $id) => $id > 0));
                $compteUser->roles()->sync($roleIds);
                // Aligner aussi profile_role (hasRole() consulte les deux).
                $profil->roles()->sync($roleIds);
            }
        }

        // Si le N+1 a changé, recalculer les N+2 de tous les subordonnés
        if ($nPlus1Changed) {
            $subordonnes = Profil::where('n_plus_1_id', $profil->id)->get();
            foreach ($subordonnes as $subordonne) {
                $subordonneNPlus2Id = null;
                if ($profil->n_plus_1_id) {
                    $subordonneNPlus2Id = $profil->n_plus_1_id;
                }
                $subordonne->update(['n_plus_2_id' => $subordonneNPlus2Id]);
            }
        }

        return redirect()->route('profils.index')
            ->with('success', 'Profil et compte mis à jour avec succès.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Profil $profil)
    {
        $user = Auth::user();

        // Vérifier l'accès : super admin peut supprimer tout, sinon vérifier les filiales
        if (! $this->canAccessProfil($profil, $user)) {
            abort(403, 'Vous n\'avez pas accès à ce profil.');
        }

        $profil->delete();

        return redirect()->route('profils.index')
            ->with('success', 'Profil supprimé avec succès !');
    }

    /**
     * Crée les comptes utilisateurs manquants pour les profils ayant un e-mail.
     */
    public function syncComptesManquants()
    {
        $actor = Auth::user();
        abort_unless($actor && ($actor->isSuperAdmin() || ($actor->isAdmin() && ! $actor->isRh())), 403);

        $query = Profil::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');
        $query = $this->applyFilialeFilter($query, $actor);

        $created = 0;
        foreach ($query->get() as $profil) {
            if ($this->findCompteForProfil($profil) !== null) {
                continue;
            }
            if ($this->syncCompteForProfil($profil, null, $profil->statut === 'actif', true, true) !== null) {
                $created++;
            }
        }

        return redirect()->route('profils.index')
            ->with('success', $created > 0
                ? "{$created} compte(s) utilisateur créé(s) pour les profils importés."
                : 'Tous les profils avec e-mail ont déjà un compte.');
    }

    /**
     * Show the import form.
     */
    public function showImport()
    {
        return Inertia::render('profils/Import');
    }

    /**
     * Import profiles from Excel file.
     */
    public function import(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('file');
            $data = Excel::toArray([], $file);

            if (empty($data) || empty($data[0])) {
                return back()->withErrors(['file' => 'Le fichier Excel est vide.']);
            }

            $rows = $data[0];
            $header = array_shift($rows); // Première ligne = en-têtes

            // Normaliser les en-têtes (minuscules, sans espaces)
            $headerMap = [];
            foreach ($header as $index => $col) {
                $normalized = strtolower(trim($col));
                $headerMap[$normalized] = $index;
            }

            // Mapping des colonnes possibles
            $columnMapping = [
                'nom' => ['nom', 'name', 'lastname', 'last_name'],
                'prenom' => ['prenom', 'firstname', 'first_name', 'prénom'],
                'matricule' => ['matricule', 'mat', 'id', 'employee_id'],
                'email' => ['email', 'e-mail', 'mail'],
                'telephone' => ['telephone', 'tel', 'phone', 'téléphone', 'mobile'],
                'fonction' => ['fonction', 'function', 'poste', 'job', 'position'],
                'departement' => ['departement', 'department', 'département', 'dept'],
                'site' => ['site', 'agence', 'agency', 'location'],
                'type_contrat' => ['type_contrat', 'type contrat', 'contract_type', 'contrat'],
                'statut' => ['status', 'etat', 'état', 'statut actif'],
                'n_plus_1' => ['n+1', 'n_plus_1', 'n plus 1', 'superieur', 'superieur hierarchique', 'superieur_hierarchique', 'manager', 'responsable'],
            ];

            $mappedColumns = [];
            foreach ($columnMapping as $dbColumn => $possibleNames) {
                foreach ($possibleNames as $name) {
                    if (isset($headerMap[$name])) {
                        $mappedColumns[$dbColumn] = $headerMap[$name];
                        break;
                    }
                }
            }

            // Vérifier que les colonnes obligatoires sont présentes
            if (! isset($mappedColumns['nom']) || ! isset($mappedColumns['prenom'])) {
                return back()->withErrors(['file' => 'Le fichier doit contenir au moins les colonnes "Nom" et "Prénom".']);
            }

            $imported = 0;
            $skipped = 0;
            $errors = [];

            DB::beginTransaction();

            try {
                foreach ($rows as $rowIndex => $row) {
                    // Ignorer les lignes vides
                    if (empty(array_filter($row))) {
                        continue;
                    }

                    $nom = trim($row[$mappedColumns['nom']] ?? '');
                    $prenom = trim($row[$mappedColumns['prenom'] ?? ''] ?? '');

                    // Ignorer si nom ou prénom est vide
                    if (empty($nom) || empty($prenom)) {
                        $skipped++;

                        continue;
                    }

                    // Récupérer les valeurs
                    $matricule = isset($mappedColumns['matricule']) ? trim($row[$mappedColumns['matricule']] ?? '') : null;
                    $email = isset($mappedColumns['email']) ? trim($row[$mappedColumns['email']] ?? '') : null;
                    $telephone = isset($mappedColumns['telephone']) ? trim($row[$mappedColumns['telephone']] ?? '') : null;
                    $fonction = isset($mappedColumns['fonction']) ? trim($row[$mappedColumns['fonction']] ?? '') : null;
                    $departement = isset($mappedColumns['departement']) ? trim($row[$mappedColumns['departement']] ?? '') : null;
                    $site = isset($mappedColumns['site']) ? trim($row[$mappedColumns['site']] ?? '') : null;
                    $typeContrat = isset($mappedColumns['type_contrat']) ? trim($row[$mappedColumns['type_contrat']] ?? '') : 'CDI';
                    $statut = isset($mappedColumns['statut']) ? trim($row[$mappedColumns['statut']] ?? '') : 'actif';

                    // Valider le type de contrat
                    if (! in_array($typeContrat, ['CDI', 'CDD', 'Stagiaire', 'Autre'])) {
                        $typeContrat = 'CDI';
                    }

                    // Valider le statut
                    if (! in_array(strtolower($statut), ['actif', 'inactif'])) {
                        $statut = 'actif';
                    } else {
                        $statut = strtolower($statut);
                    }

                    // Gérer le matricule : utiliser celui du fichier s'il existe, sinon générer automatiquement
                    if (empty($matricule)) {
                        // Générer le matricule automatiquement si absent
                        $matricule = Profil::generateMatricule();
                    } else {
                        // Vérifier si le matricule existe déjà dans la base de données
                        if (Profil::where('matricule', $matricule)->exists()) {
                            $skipped++;
                            $errors[] = 'Ligne '.($rowIndex + 2).": Matricule déjà existant ($matricule)";

                            continue;
                        }
                    }

                    // Vérifier si l'email existe déjà (si fourni)
                    if ($email && Profil::where('email', $email)->exists()) {
                        $skipped++;
                        $errors[] = 'Ligne '.($rowIndex + 2).": Email déjà existant ($email)";

                        continue;
                    }

                    // Synchroniser le département avec la table departements
                    if ($departement) {
                        $departementTrimmed = trim($departement);

                        // Normaliser "informatique" en "IT"
                        $departementNormalized = preg_replace('/informatique/i', 'IT', $departementTrimmed);

                        // Normaliser les variations communes
                        // Supprimer "Direction" au début si présent
                        $departementNormalized = preg_replace('/^direction\s+/i', '', $departementNormalized);

                        // Normaliser "exploitation" et toutes ses variations
                        if (preg_match('/exploitation/i', $departementNormalized)) {
                            $departementNormalized = 'EXPLOITATION';
                        }

                        // Mettre en majuscules pour uniformiser
                        $departementNormalized = strtoupper(trim($departementNormalized));

                        // Chercher un département existant avec un nom similaire (insensible à la casse)
                        // D'abord chercher par nom exact (en majuscules)
                        $departementModel = Departement::whereRaw('UPPER(TRIM(nom)) = ?', [$departementNormalized])->first();

                        // Si pas trouvé, chercher en supprimant "DIRECTION" du nom existant
                        if (! $departementModel) {
                            $departementModel = Departement::whereRaw('UPPER(TRIM(REPLACE(REPLACE(nom, "DIRECTION ", ""), "DIRECTION", ""))) = ?', [$departementNormalized])->first();
                        }

                        // Si pas trouvé, chercher par mot-clé (pour regrouper les variations)
                        if (! $departementModel) {
                            // Extraire le mot-clé principal (premier mot significatif)
                            $keywords = explode(' ', $departementNormalized);
                            $mainKeyword = ! empty($keywords) ? $keywords[0] : $departementNormalized;

                            // Chercher les départements existants qui contiennent ce mot-clé
                            $existingDepartements = Departement::whereRaw('UPPER(TRIM(nom)) LIKE ?', ["%{$mainKeyword}%"])
                                ->orWhereRaw('UPPER(TRIM(REPLACE(REPLACE(nom, "DIRECTION ", ""), "DIRECTION", ""))) LIKE ?', ["%{$mainKeyword}%"])
                                ->get();

                            // Si on trouve un département existant avec le même mot-clé, l'utiliser
                            if ($existingDepartements->isNotEmpty()) {
                                $departementModel = $existingDepartements->first();
                                // Mettre à jour le nom normalisé pour correspondre au département existant
                                $departementNormalized = $departementModel->nom;
                            }
                        }

                        // Si pas trouvé, créer le département
                        if (! $departementModel) {
                            $departementModel = Departement::create([
                                'nom' => $departementNormalized,
                                'description' => 'Direction '.strtolower($departementNormalized),
                                'actif' => true,
                            ]);
                        }

                        // Utiliser le nom normalisé du département pour le profil
                        $departement = $departementModel->nom;
                    }

                    // Trouver ou créer la filiale "Sénégal" par défaut pour tous les profils importés
                    $filialeSenegal = Filiale::firstOrCreate(
                        ['nom' => 'Sénégal'],
                        [
                            'nom' => 'Sénégal',
                            'description' => 'Filiale Sénégal',
                            'actif' => true,
                        ]
                    );

                    // Synchroniser le site/agence avec la table agences
                    if ($site) {
                        $siteNormalized = trim($site);

                        // Chercher ou créer l'agence dans la table agences
                        $agenceModel = Agence::firstOrCreate(
                            ['nom' => $siteNormalized],
                            [
                                'nom' => $siteNormalized,
                                'code_agent' => null, // Laisser vide
                                'description' => 'Agence '.$siteNormalized,
                                'actif' => true,
                                'filiale_id' => $filialeSenegal->id, // Lier à la filiale Sénégal par défaut
                            ]
                        );

                        // Si l'agence existait déjà sans filiale, la mettre à jour
                        if (! $agenceModel->filiale_id) {
                            $agenceModel->filiale_id = $filialeSenegal->id;
                            $agenceModel->save();
                        }

                        // Utiliser le nom normalisé de l'agence pour le profil
                        $site = $agenceModel->nom;
                    }

                    // Gérer le N+1 si présent dans le fichier
                    $nPlus1Id = null;
                    $nPlus2Id = null;

                    if (isset($mappedColumns['n_plus_1'])) {
                        $nPlus1Value = trim($row[$mappedColumns['n_plus_1']] ?? '');

                        if (! empty($nPlus1Value)) {
                            // Chercher le profil N+1 par matricule, email, ou nom/prénom
                            $nPlus1 = null;

                            // Essayer d'abord par matricule
                            $nPlus1 = Profil::where('matricule', $nPlus1Value)->first();

                            // Si pas trouvé, essayer par email
                            if (! $nPlus1) {
                                $nPlus1 = Profil::where('email', $nPlus1Value)->first();
                            }

                            // Si pas trouvé, essayer par nom et prénom (insensible à la casse et aux accents)
                            if (! $nPlus1) {
                                // Fonction pour normaliser les accents
                                $normalizeAccents = function ($str) {
                                    $str = strtolower($str);
                                    $str = str_replace(
                                        ['à', 'á', 'â', 'ã', 'ä', 'å', 'è', 'é', 'ê', 'ë', 'ì', 'í', 'î', 'ï', 'ò', 'ó', 'ô', 'õ', 'ö', 'ù', 'ú', 'û', 'ü', 'ý', 'ÿ', 'ç', 'ñ'],
                                        ['a', 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'y', 'y', 'c', 'n'],
                                        $str
                                    );

                                    return $str;
                                };

                                // Normaliser la valeur (supprimer les espaces multiples, normaliser la casse et les accents)
                                $nPlus1ValueNormalized = preg_replace('/\s+/', ' ', trim($nPlus1Value));
                                $nPlus1ValueLower = $normalizeAccents($nPlus1ValueNormalized);

                                $nameParts = preg_split('/\s+/', trim($nPlus1ValueNormalized));
                                if (count($nameParts) >= 2) {
                                    // Essayer "Prénom Nom" (insensible à la casse et aux accents)
                                    $prenomN1 = trim($nameParts[0]);
                                    $nomN1 = trim($nameParts[count($nameParts) - 1]); // Dernier mot = nom

                                    // Récupérer les profils filtrés selon le rôle et comparer en PHP (plus simple pour gérer les accents)
                                    $allProfilsQuery = Profil::select('id', 'nom', 'prenom', 'matricule');
                                    $allProfilsQuery = $this->applyFilialeFilter($allProfilsQuery, $user);
                                    $allProfils = $allProfilsQuery->get();
                                    foreach ($allProfils as $profilCandidate) {
                                        $prenomNormalized = $normalizeAccents($profilCandidate->prenom);
                                        $nomNormalized = $normalizeAccents($profilCandidate->nom);

                                        if ($prenomNormalized === $normalizeAccents($prenomN1) &&
                                            $nomNormalized === $normalizeAccents($nomN1)) {
                                            $nPlus1 = $profilCandidate;
                                            break;
                                        }
                                    }

                                    // Si pas trouvé, essayer "Nom Prénom"
                                    if (! $nPlus1 && count($nameParts) == 2) {
                                        foreach ($allProfils as $profilCandidate) {
                                            $prenomNormalized = $normalizeAccents($profilCandidate->prenom);
                                            $nomNormalized = $normalizeAccents($profilCandidate->nom);

                                            if ($nomNormalized === $normalizeAccents($nameParts[0]) &&
                                                $prenomNormalized === $normalizeAccents($nameParts[1])) {
                                                $nPlus1 = $profilCandidate;
                                                break;
                                            }
                                        }
                                    }

                                    // Si toujours pas trouvé, essayer une recherche partielle sur le nom complet (insensible aux accents)
                                    if (! $nPlus1) {
                                        $allProfilsQuery = Profil::select('id', 'nom', 'prenom', 'matricule');
                                        $allProfilsQuery = $this->applyFilialeFilter($allProfilsQuery, $user);
                                        $allProfils = $allProfilsQuery->get();
                                        foreach ($allProfils as $profilCandidate) {
                                            $fullNameCandidate = $normalizeAccents(trim($profilCandidate->prenom.' '.$profilCandidate->nom));
                                            $fullNameCandidateReverse = $normalizeAccents(trim($profilCandidate->nom.' '.$profilCandidate->prenom));

                                            // Correspondance exacte (insensible aux accents)
                                            if ($fullNameCandidate === $nPlus1ValueLower ||
                                                $fullNameCandidateReverse === $nPlus1ValueLower) {
                                                $nPlus1 = $profilCandidate;
                                                break;
                                            }

                                            // Correspondance partielle (si le nom recherché contient le prénom et le nom)
                                            $nPlus1ValueWords = explode(' ', $nPlus1ValueLower);
                                            if (count($nPlus1ValueWords) >= 2) {
                                                $firstWord = $nPlus1ValueWords[0];
                                                $lastWord = $nPlus1ValueWords[count($nPlus1ValueWords) - 1];

                                                // Vérifier si le prénom commence par le premier mot et le nom correspond au dernier mot
                                                $prenomNormalized = $normalizeAccents($profilCandidate->prenom);
                                                $nomNormalized = $normalizeAccents($profilCandidate->nom);

                                                if ((strpos($fullNameCandidate, $firstWord) === 0 || strpos($prenomNormalized, $firstWord) === 0) &&
                                                    (strpos($fullNameCandidate, $lastWord) !== false || strpos($nomNormalized, $lastWord) === 0)) {
                                                    $nPlus1 = $profilCandidate;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    // Si un seul mot, chercher par nom ou prénom (insensible à la casse)
                                    $nPlus1 = Profil::where(function ($q) use ($nPlus1Value) {
                                        $q->whereRaw('LOWER(nom) = ?', [strtolower($nPlus1Value)])
                                            ->orWhereRaw('LOWER(prenom) = ?', [strtolower($nPlus1Value)]);
                                    })->first();
                                }
                            }

                            if ($nPlus1) {
                                // Vérifier que le N+1 trouvé n'est pas le profil en cours de création
                                // (comparer par matricule si on l'a déjà, sinon par nom/prénom)
                                $isSelfReference = false;
                                if (! empty($matricule) && $nPlus1->matricule === $matricule) {
                                    $isSelfReference = true;
                                } elseif (strtolower($nPlus1->prenom) === strtolower($prenom) &&
                                          strtolower($nPlus1->nom) === strtolower($nom)) {
                                    $isSelfReference = true;
                                }

                                if (! $isSelfReference) {
                                    $nPlus1Id = $nPlus1->id;

                                    // Calculer automatiquement N+2 : le N+1 du N+1
                                    // Mais seulement si le N+2 est différent du N+1 (éviter les boucles)
                                    if ($nPlus1->n_plus_1_id && $nPlus1->n_plus_1_id != $nPlus1Id) {
                                        $nPlus2Id = $nPlus1->n_plus_1_id;
                                    }
                                } else {
                                    // Un profil ne peut pas être son propre N+1
                                    $errors[] = 'Ligne '.($rowIndex + 2).": Le N+1 ($nPlus1Value) correspond au profil en cours de création pour $prenom $nom. Ignoré.";
                                }
                            } else {
                                // N+1 non trouvé, ajouter un avertissement mais continuer l'import
                                $errors[] = 'Ligne '.($rowIndex + 2).": N+1 non trouvé ($nPlus1Value) pour $prenom $nom. Le profil sera créé sans N+1.";
                            }
                        }
                    }

                    $emailNorm = $email ? mb_strtolower(trim($email)) : null;

                    // Créer le profil avec la filiale Sénégal
                    $profil = Profil::create([
                        'nom' => $nom,
                        'prenom' => $prenom,
                        'matricule' => $matricule,
                        'email' => $emailNorm,
                        'telephone' => $telephone ?: null,
                        'fonction' => $fonction ?: null,
                        'departement' => $departement ?: null,
                        'site' => $site ?: null,
                        'type_contrat' => $typeContrat,
                        'statut' => $statut,
                        'n_plus_1_id' => $nPlus1Id,
                        'n_plus_2_id' => $nPlus2Id,
                        'filiale_id' => $filialeSenegal->id,
                    ]);

                    if ($emailNorm) {
                        $this->syncCompteForProfil(
                            $profil,
                            null,
                            $statut === 'actif',
                            true,
                            true
                        );
                    }

                    $imported++;
                }

                DB::commit();

                $message = "$imported profil(s) importé(s) avec succès.";
                if ($skipped > 0) {
                    $message .= " $skipped ligne(s) ignorée(s).";
                }
                if (! empty($errors)) {
                    $message .= "\n\nErreurs rencontrées:\n".implode("\n", $errors);
                }

                return redirect()->route('profils.index')
                    ->with('success', $message)
                    ->with('import_errors', $errors);

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Erreur lors de l\'import Excel: '.$e->getMessage());

                return back()->withErrors(['file' => 'Erreur lors de l\'import: '.$e->getMessage()]);
            }

        } catch (\Exception $e) {
            Log::error('Erreur lors de la lecture du fichier Excel: '.$e->getMessage());

            return back()->withErrors(['file' => 'Erreur lors de la lecture du fichier: '.$e->getMessage()]);
        }
    }

    /**
     * Export profiles to Excel file.
     */
    public function export(Request $request)
    {
        $user = Auth::user();

        // Construire la requête de base (même logique que index)
        $query = Profil::query();

        // Admin et RH voient tous les profils
        if ($user && ($user->isAdmin() || $user->isRh())) {
            // Pas de restriction pour l'admin et RH
        } else {
            // Les autres voient uniquement leur propre profil et leurs subordonnés
            $profil = $user?->profil;
            if ($profil) {
                $query->where(function ($q) use ($profil) {
                    $q->where('id', $profil->id)
                        ->orWhere('n_plus_1_id', $profil->id);
                });
            } else {
                $query->where('id', 0);
            }
        }

        // Appliquer les mêmes filtres que dans index
        if ($request->has('statut') && $request->statut !== '') {
            $query->where('statut', $request->statut);
        }

        if ($request->has('departement') && $request->departement) {
            $departement = Departement::find($request->departement);
            if ($departement) {
                $query->where('departement', $departement->nom);
            }
        }

        if ($request->has('fonction') && $request->fonction) {
            $query->where('fonction', 'like', "%{$request->fonction}%");
        }

        if ($request->has('site') && $request->site) {
            $agence = Agence::find($request->site);
            if ($agence) {
                $query->where('site', $agence->nom);
            }
        }

        if ($request->has('type_contrat') && $request->type_contrat) {
            $query->where('type_contrat', $request->type_contrat);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('matricule', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Trier par nom puis prénom
        $query->orderBy('nom')->orderBy('prenom');

        $fileName = 'profils_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new ProfilsExport($query), $fileName);
    }
}
