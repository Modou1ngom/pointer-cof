<?php

namespace App\Http\Controllers;

use App\Models\Agence;
use App\Models\Departement;
use App\Models\Filiale;
use App\Models\Profil;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        return redirect('/profils'.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            'must_change_password' => 'nullable|boolean',
            'roles' => 'nullable|array',
            'roles.*' => 'required|integer|exists:roles,id',
            'profil_id' => 'nullable|integer|exists:profiles,id',
            'filiales' => 'nullable|array',
            'filiales.*' => 'required|integer|exists:filiales,id',
            'agences' => 'nullable|array',
            'agences.*' => 'required|integer|exists:agences,id',
            'default_agence_id' => 'nullable|integer|exists:agences,id',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'must_change_password' => $validated['must_change_password'] ?? true,
        ]);

        // Attacher les rôles si fournis
        if (! empty($validated['roles']) && is_array($validated['roles'])) {
            $user->roles()->sync(array_map('intval', $validated['roles']));
        }

        // Associer le profil si sélectionné (avant l'attachement des filiales)
        $profilFilialeId = null;
        if (isset($validated['profil_id']) && ! empty($validated['profil_id'])) {
            $profil = Profil::find($validated['profil_id']);
            if ($profil) {
                // Récupérer la filiale du profil pour l'ajouter aux environnements
                if ($profil->filiale_id) {
                    $profilFilialeId = $profil->filiale_id;
                }

                // Vérifier que l'email n'est pas déjà utilisé par un autre profil
                $existingProfil = Profil::where('email', $validated['email'])
                    ->where('id', '!=', $profil->id)
                    ->first();

                if (! $existingProfil) {
                    // Mettre à jour l'email du profil pour qu'il corresponde à l'email de l'utilisateur
                    $profil->update(['email' => $validated['email']]);
                }
            }
        }

        // Attacher les filiales/environnements
        $filialesToAttach = [];
        if (! empty($validated['filiales']) && is_array($validated['filiales'])) {
            $filialesToAttach = array_map('intval', $validated['filiales']);
        }

        // Ajouter automatiquement la filiale du profil aux environnements si elle existe
        if ($profilFilialeId && ! in_array($profilFilialeId, $filialesToAttach)) {
            $filialesToAttach[] = $profilFilialeId;
        }

        if (! empty($filialesToAttach)) {
            $user->filiales()->sync($filialesToAttach);
        }

        // Attacher les agences si fournies.
        if (! empty($validated['agences']) && is_array($validated['agences'])) {
            $agenceIds = array_map('intval', $validated['agences']);
            $defaultAgenceId = isset($validated['default_agence_id']) ? (int) $validated['default_agence_id'] : null;

            if ($defaultAgenceId !== null && ! in_array($defaultAgenceId, $agenceIds, true)) {
                return back()->withErrors([
                    'default_agence_id' => 'L\'agence domiciliaire doit appartenir aux agences sélectionnées.',
                ])->withInput();
            }

            if ($defaultAgenceId === null) {
                $defaultAgenceId = $agenceIds[0] ?? null;
            }

            $syncData = [];
            foreach ($agenceIds as $agenceId) {
                $syncData[$agenceId] = [
                    'is_default' => $defaultAgenceId !== null && $agenceId === $defaultAgenceId,
                ];
            }

            $user->agences()->sync($syncData);
        }

        return redirect()->route('users.index')
            ->with('success', 'Utilisateur créé avec succès !');
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $user->load(['profil', 'roles', 'filiales', 'agences']);

        return Inertia::render('users/Show', [
            'user' => $user,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(User $user)
    {
        $user->load('profil');
        $profil = $user->profil;
        if ($profil === null && trim((string) $user->email) !== '') {
            $profil = Profil::query()
                ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim((string) $user->email))])
                ->first();
        }
        if ($profil === null && trim((string) $user->matricule) !== '') {
            $profil = Profil::query()->where('matricule', trim((string) $user->matricule))->first();
        }

        if ($profil !== null) {
            return redirect()->route('profils.edit', $profil);
        }

        return redirect()->route('profils.index')
            ->with('error', 'Aucune fiche profil associée à cet utilisateur.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
            'must_change_password' => 'nullable|boolean',
            'roles' => 'nullable|array',
            'roles.*' => 'required|integer|exists:roles,id',
            'profil_id' => 'nullable|integer|exists:profiles,id',
            'filiales' => 'nullable|array',
            'filiales.*' => 'required|integer|exists:filiales,id',
            'agences' => 'nullable|array',
            'agences.*' => 'required|integer|exists:agences,id',
            'default_agence_id' => 'nullable|integer|exists:agences,id',
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'must_change_password' => $validated['must_change_password'] ?? false,
        ];

        // Mettre à jour le mot de passe seulement s'il est fourni
        if (! empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        // Synchroniser les rôles
        if (isset($validated['roles']) && is_array($validated['roles'])) {
            $user->roles()->sync(array_map('intval', $validated['roles']));
        } else {
            $user->roles()->sync([]);
        }

        // Associer ou dissocier le profil (avant la synchronisation des filiales)
        $profilFilialeId = null;

        // D'abord, dissocier le profil actuel si l'email correspond
        $currentProfil = Profil::where('email', $user->email)->first();
        if ($currentProfil && (! isset($validated['profil_id']) || $validated['profil_id'] != $currentProfil->id)) {
            // Dissocier en mettant l'email du profil actuel à null
            $currentProfil->update(['email' => null]);
        }

        // Associer le nouveau profil si sélectionné
        if (isset($validated['profil_id']) && ! empty($validated['profil_id'])) {
            $profil = Profil::find($validated['profil_id']);
            if ($profil) {
                // Récupérer la filiale du profil pour l'ajouter aux environnements
                if ($profil->filiale_id) {
                    $profilFilialeId = $profil->filiale_id;
                }

                // Mettre à jour l'email du profil pour qu'il corresponde à l'email de l'utilisateur
                // Vérifier que l'email n'est pas déjà utilisé par un autre profil
                $existingProfil = Profil::where('email', $validated['email'])
                    ->where('id', '!=', $profil->id)
                    ->first();

                if (! $existingProfil) {
                    $profil->update(['email' => $validated['email']]);
                }
            }
        }

        // Synchroniser les filiales/environnements
        $filialesToAttach = [];
        if (isset($validated['filiales']) && is_array($validated['filiales'])) {
            $filialesToAttach = array_map('intval', $validated['filiales']);
        }

        // Ajouter automatiquement la filiale du profil aux environnements si elle existe
        if ($profilFilialeId && ! in_array($profilFilialeId, $filialesToAttach)) {
            $filialesToAttach[] = $profilFilialeId;
        }

        $user->filiales()->sync($filialesToAttach);

        // Synchroniser les agences rattachees a l'utilisateur.
        if (isset($validated['agences']) && is_array($validated['agences'])) {
            $agenceIds = array_map('intval', $validated['agences']);
            $defaultAgenceId = isset($validated['default_agence_id']) ? (int) $validated['default_agence_id'] : null;

            if ($defaultAgenceId !== null && ! in_array($defaultAgenceId, $agenceIds, true)) {
                return back()->withErrors([
                    'default_agence_id' => 'L\'agence domiciliaire doit appartenir aux agences sélectionnées.',
                ])->withInput();
            }

            if (! empty($agenceIds) && $defaultAgenceId === null) {
                $defaultAgenceId = $agenceIds[0];
            }

            $syncData = [];
            foreach ($agenceIds as $agenceId) {
                $syncData[$agenceId] = [
                    'is_default' => $defaultAgenceId !== null && $agenceId === $defaultAgenceId,
                ];
            }

            $user->agences()->sync($syncData);
        } else {
            $user->agences()->sync([]);
        }

        return redirect()->route('users.index')
            ->with('success', 'Utilisateur mis à jour avec succès !');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'Utilisateur supprimé avec succès !');
    }

    /**
     * Toggle the active status of a user.
     * Désactivation compte → profil RH inactif (ne doit plus apparaître en absence).
     */
    public function toggle(User $user)
    {
        $user->is_active = ! $user->is_active;
        $user->save();

        $user->profilCollaborateurAssocie();
        $profil = $user->profil;
        if ($profil !== null) {
            $profil->statut = $user->is_active ? 'actif' : 'inactif';
            $profil->save();
        }

        $status = $user->is_active ? 'activé' : 'désactivé';

        return back()->with('success', "Utilisateur {$status} avec succès !");
    }
}
