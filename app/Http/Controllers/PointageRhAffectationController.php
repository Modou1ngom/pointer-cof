<?php

namespace App\Http\Controllers;

use App\Models\Agence;
use App\Models\Departement;
use App\Models\Filiale;
use App\Models\PointageAffectation;
use App\Models\PointageAffectationHistory;
use App\Models\PointageAuditLog;
use App\Models\PointageDeclaration;
use App\Models\Profil;
use App\Models\User;
use App\Services\Pointage\PointageDeclarationPresenceService;
use App\Support\PointageDeclarationCouverture;
use App\Support\PointageDeclarationTypes;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class PointageRhAffectationController extends Controller
{
    private const TYPE_POINTAGE = ['qr_et_gps', 'qr_seul', 'gps_seul'];

    private const MODE_VALIDATION = ['validation_manager', 'validation_rh', 'validation_mixte'];

    private const NIVEAU_ACCES = ['pointage_complet', 'pointage_limite', 'consultation'];

    public function lookup(Request $request): JsonResponse
    {
        $actor = $this->actor();

        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $profil = Profil::query()
            ->whereNotNull('email')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if ($profil === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Aucun collaborateur trouvé pour cet e-mail dans les habilitations.',
            ], 404);
        }

        $matchedUser = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        $this->logHistory($matchedUser?->id, $actor->id, 'lookup', [
            'email' => $email,
            'profil_id' => $profil->id,
        ]);

        $affectation = PointageAffectation::query()->where('profil_id', $profil->id)->first();

        $settings = null;
        $agences = [];
        if ($affectation !== null) {
            $settings = $this->settingsPayloadFromAffectation($affectation);
            try {
                $agences = $this->agencesPayloadFromAffectation($affectation);
            } catch (QueryException $e) {
                Log::warning('Pointage affectation: lecture agences impossible.', ['exception' => $e->getMessage()]);
            }
        }

        return response()->json([
            'ok' => true,
            'profil' => $this->profilPayload($profil),
            'user' => $matchedUser ? ['id' => $matchedUser->id, 'email' => $matchedUser->email, 'name' => $matchedUser->name] : null,
            'affectation' => $affectation ? $this->affectationPayload($affectation) : null,
            'already_enrolled' => $affectation !== null,
            'settings' => $settings,
            'agences_autorisees' => $agences,
        ]);
    }

    public function storeProfil(Request $request): JsonResponse
    {
        $actor = $this->actor();

        try {
            $validated = $request->validate([
                'matricule' => 'required|string|max:50|unique:profiles,matricule',
                'nom' => 'required|string|max:255',
                'prenom' => 'required|string|max:255',
                'fonction' => 'nullable|string',
                'departement' => 'nullable|string',
                'email' => 'required|email|unique:profiles,email|unique:users,email',
                'telephone' => ['nullable', 'string', 'max:20', 'regex:/^(\\+221|00221|221)?[0-9]{9}$/'],
                'agence_id' => 'nullable|integer|exists:agences,id',
                'filiale_id' => 'nullable|integer|exists:filiales,id',
                'type_contrat' => 'nullable|in:CDI,CDD,Stagiaire,Autre',
                'statut' => 'nullable|in:actif,inactif',
                'n_plus_1_id' => 'nullable|exists:profiles,id',
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
                'must_change_password' => 'nullable|boolean',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Données invalides.',
                'errors' => $e->errors(),
            ], 422);
        }

        $validated['matricule'] = trim($validated['matricule']);

        $nPlus2Id = null;
        if (! empty($validated['n_plus_1_id'])) {
            $nPlus1 = Profil::find($validated['n_plus_1_id']);
            if ($nPlus1 && $nPlus1->n_plus_1_id && $nPlus1->n_plus_1_id != $validated['n_plus_1_id']) {
                $nPlus2Id = $nPlus1->n_plus_1_id;
            }
        }

        $filialeId = $validated['filiale_id'] ?? null;
        $agenceNom = null;
        if (! empty($validated['agence_id'])) {
            $agenceSelection = Agence::query()->find((int) $validated['agence_id']);
            if ($agenceSelection !== null) {
                $agenceNom = $agenceSelection->nom;
                if (! $filialeId && $agenceSelection->filiale_id) {
                    $filialeId = $agenceSelection->filiale_id;
                }
            }
        }
        if (! $filialeId && ! $actor->isSuperAdmin()) {
            $userFiliales = $actor->filiales()->get();
            if ($userFiliales->count() > 0) {
                $filialeId = $userFiliales->first()->id;
            } elseif ($actor->profil?->filiale_id) {
                $filialeId = $actor->profil->filiale_id;
            }
        }

        $email = mb_strtolower(trim($validated['email']));

        try {
            [$profil, $createdUser] = DB::transaction(function () use ($validated, $filialeId, $nPlus2Id, $email, $agenceNom) {
                $matricule = trim($validated['matricule']);

                $profil = Profil::query()->create([
                    'nom' => $validated['nom'],
                    'prenom' => $validated['prenom'],
                    'matricule' => $matricule,
                    'fonction' => $validated['fonction'] ?? null,
                    'departement' => $validated['departement'] ?? null,
                    'email' => $email,
                    'telephone' => $validated['telephone'] ?? null,
                    'site' => $agenceNom,
                    'filiale_id' => $filialeId,
                    'type_contrat' => $validated['type_contrat'] ?? 'CDI',
                    'statut' => $validated['statut'] ?? 'actif',
                    'n_plus_1_id' => $validated['n_plus_1_id'] ?? null,
                    'n_plus_2_id' => $nPlus2Id,
                ]);

                $displayName = trim($validated['prenom'].' '.$validated['nom']);
                $user = User::query()->create([
                    'name' => $displayName !== '' ? $displayName : $email,
                    'email' => $email,
                    'matricule' => $matricule,
                    'password' => Hash::make($validated['password']),
                    'must_change_password' => $validated['must_change_password'] ?? true,
                ]);

                if ($filialeId) {
                    $user->filiales()->sync([(int) $filialeId]);
                }

                if (! empty($validated['agence_id'])) {
                    $agence = Agence::query()->where('id', (int) $validated['agence_id'])->where('actif', true)->first();
                    if ($agence !== null) {
                        $user->agences()->sync([
                            $agence->id => ['is_default' => true],
                        ]);
                    }
                }

                return [$profil, $user];
            });
        } catch (QueryException $e) {
            Log::warning('Pointage: création profil + utilisateur impossible.', ['exception' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Création impossible (contrainte base de données).',
            ], 422);
        }

        $this->logHistory($createdUser->id, $actor->id, 'profil_user_create_pointage', [
            'profil_id' => $profil->id,
            'email' => $email,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Profil et compte utilisateur créés. Cliquez sur « Enrôler » pour finaliser l’affectation pointage.',
            'profil' => $this->profilPayload($profil),
            'user' => ['id' => $createdUser->id, 'email' => $createdUser->email, 'name' => $createdUser->name],
            'already_enrolled' => false,
            'affectation' => null,
            'settings' => null,
            'agences_autorisees' => [],
        ]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $actor = $this->actor();

        if ($request->has('statut_activation')) {
            $request->merge([
                'statut_activation' => filter_var($request->input('statut_activation'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
            ]);
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'type_pointage' => 'sometimes|string|in:'.implode(',', self::TYPE_POINTAGE),
            'mode_validation' => 'sometimes|string|in:'.implode(',', self::MODE_VALIDATION),
            'date_affectation' => 'nullable|date',
            'date_fin_affectation' => 'nullable|date',
            'statut_activation' => 'sometimes|boolean',
            'agences' => 'nullable|array',
            'agences.*.agence_id' => 'required|integer|exists:agences,id',
            'agences.*.date_debut_autorisation' => 'nullable|date',
            'agences.*.date_fin_autorisation' => 'nullable|date',
            'agences.*.statut_agence' => 'required|string|in:actif,inactif',
            'agences.*.niveau_acces' => 'required|string|in:'.implode(',', self::NIVEAU_ACCES),
            'agences.*.is_default' => 'nullable|boolean',
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $profil = Profil::query()
            ->whereNotNull('email')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if ($profil === null) {
            return response()->json(['ok' => false, 'message' => 'Aucun profil habilitations pour cet e-mail.'], 404);
        }

        if (PointageAffectation::query()->where('profil_id', $profil->id)->exists()) {
            return response()->json(['ok' => false, 'message' => 'Ce collaborateur est déjà enrôlé au pointage.'], 422);
        }

        if (
            ! empty($validated['date_affectation'])
            && ! empty($validated['date_fin_affectation'])
            && $validated['date_fin_affectation'] < $validated['date_affectation']
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'La date de fin doit être postérieure ou égale à la date d’affectation.',
            ], 422);
        }

        $matchedUser = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();

        try {
            $affectation = DB::transaction(function () use ($validated, $profil, $matchedUser, $actor) {
                $affectation = PointageAffectation::query()->create([
                    'profil_id' => $profil->id,
                    'user_id' => $matchedUser?->id,
                    'type_pointage' => $validated['type_pointage'] ?? 'qr_et_gps',
                    'mode_validation' => $validated['mode_validation'] ?? 'validation_manager',
                    'date_affectation' => $validated['date_affectation'] ?? null,
                    'date_fin_affectation' => $validated['date_fin_affectation'] ?? null,
                    'statut_activation' => $validated['statut_activation'] ?? true,
                    'enrolled_by' => $actor->id,
                    'enrolled_at' => now(),
                ]);
                $affectation->syncUserLinkFromProfilEmail();
                $affectation->syncLegacyUserSettings();

                if (! empty($validated['agences'])) {
                    $this->syncAgencesOnAffectation($affectation, $validated['agences'], $actor);
                }

                $affectation->syncAgencesToUserPivot();

                return $affectation->fresh(['profil', 'user', 'agences']);
            });
        } catch (QueryException $e) {
            Log::warning('Pointage affectation: enrôlement impossible.', ['exception' => $e->getMessage()]);

            $msg = 'Enrôlement impossible (contrainte base de données).';
            if (str_contains($e->getMessage(), 'Duplicate') && str_contains($e->getMessage(), 'profil_id')) {
                $msg = 'Ce collaborateur est déjà enrôlé au pointage.';
            }

            return response()->json([
                'ok' => false,
                'message' => $msg,
            ], 422);
        }

        if ($affectation->user_id) {
            $this->logHistory($affectation->user_id, $actor->id, 'enrolement', ['profil_id' => $profil->id]);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Collaborateur enrôlé pour le pointage.',
            'affectation' => $this->affectationPayload($affectation),
            'profil' => $this->profilPayload($profil),
            'user' => $matchedUser ? ['id' => $matchedUser->id, 'email' => $matchedUser->email, 'name' => $matchedUser->name] : null,
            'settings' => $this->settingsPayloadFromAffectation($affectation),
            'agences_autorisees' => $this->agencesPayloadFromAffectation($affectation),
        ]);
    }

    public function show(PointageAffectation $affectation): JsonResponse
    {
        $this->actor();
        $affectation->load(['profil', 'user', 'agences']);
        $affectation->syncUserLinkFromProfilEmail();
        $user = $affectation->user;

        return response()->json([
            'ok' => true,
            'affectation' => $this->affectationPayload($affectation),
            'profil' => $affectation->profil ? $this->profilPayload($affectation->profil) : null,
            'user' => $user ? ['id' => $user->id, 'email' => $user->email, 'name' => $user->name] : null,
            'settings' => $this->settingsPayloadFromAffectation($affectation),
            'agences_autorisees' => $this->agencesPayloadFromAffectation($affectation),
        ]);
    }

    public function toggleStatut(PointageAffectation $affectation): JsonResponse|RedirectResponse
    {
        $actor = $this->actor();
        $affectation->update(['statut_activation' => ! $affectation->statut_activation]);
        $affectation->syncLegacyUserSettings();
        if ($affectation->user_id) {
            $this->logHistory($affectation->user_id, $actor->id, 'statut_activation', [
                'statut_activation' => $affectation->statut_activation,
            ]);
        }
        if (request()->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $affectation->statut_activation ? 'Affectation activée.' : 'Affectation désactivée.',
                'affectation' => $this->affectationPayload($affectation->fresh()),
            ]);
        }

        return back()->with('success', $affectation->statut_activation ? 'Affectation activée.' : 'Affectation désactivée.');
    }

    public function declarerConge(Request $request, PointageAffectation $affectation): RedirectResponse
    {
        $actor = $this->actor();
        $affectation->syncUserLinkFromProfilEmail();
        if (! $affectation->user_id) {
            return back()->with('error', 'Cet employé n’a pas de compte utilisateur : impossible de déclarer un congé.');
        }
        $this->ensureCanManageUser($actor, $affectation->user);

        $type = PointageDeclarationTypes::normalize((string) $request->input('type', 'conge_annuel'));
        if (! in_array($type, ['conge_annuel', 'conge_maladie', 'permission_exceptionnelle', 'allaitement', 'mission'], true)) {
            $type = 'conge_annuel';
        }
        $request->merge(['type' => $type]);
        $validated = $request->validate(PointageDeclarationTypes::storeRules($type));
        $validated = PointageDeclarationTypes::finalizeValidated($type, $validated);

        $declaration = PointageDeclaration::create([
            'user_id' => $affectation->user_id,
            'type' => $type,
            'date_concernee' => $validated['date_concernee'],
            'date_fin' => $validated['date_fin'] ?? $validated['date_concernee'],
            'heure_debut' => $validated['heure_debut'] ?? null,
            'heure_fin' => $validated['heure_fin'] ?? null,
            'lieu' => $validated['lieu'] ?? null,
            'motif' => $validated['motif'],
            'commentaire' => $validated['commentaire'] ?? null,
            'statut' => 'valide',
            'rh_user_id' => $actor->id,
            'rh_decided_at' => now(),
            'rh_comment' => 'Déclaré par RH depuis l’affectation.',
        ]);

        app(PointageDeclarationPresenceService::class)->appliquerApresValidationRh($declaration);

        $this->logHistory($affectation->user_id, $actor->id, 'declaration_conge', [
            'declaration_id' => $declaration->id,
            'type' => $type,
            'date_concernee' => $validated['date_concernee'],
            'date_fin' => $validated['date_fin'] ?? $validated['date_concernee'],
        ]);
        PointageAuditLog::record(
            $actor,
            'DECLARATION_CONGE_RH',
            'Congé déclaré par RH pour '.$affectation->user->name,
            null,
            $request->ip(),
            'ok',
            ['declaration_id' => $declaration->id, 'user_id' => $affectation->user_id]
        );

        $success = $type === 'allaitement'
            ? 'Allaitement enregistré : l’horaire d’entrée/sortie sera ajusté sur la période (pointage obligatoire).'
            : PointageDeclarationTypes::label($type).' enregistré : l’employé n’est plus compté en absence sur cette période.';

        return back()->with('success', $success);
    }

    public function retirerConge(Request $request, PointageAffectation $affectation): RedirectResponse
    {
        $actor = $this->actor();
        $affectation->syncUserLinkFromProfilEmail();
        if (! $affectation->user_id) {
            return back()->with('error', 'Cet employé n’a pas de compte utilisateur.');
        }
        $this->ensureCanManageUser($actor, $affectation->user);

        $declarationId = (int) $request->input('declaration_id', 0);
        $couverture = PointageDeclarationCouverture::pourUserJour(
            (int) $affectation->user_id,
            Carbon::today(),
            PointageDeclarationTypes::TYPES_NOTES_RH,
        );

        if ($declarationId <= 0) {
            $declarationId = (int) ($couverture['declaration_id'] ?? 0);
        }

        if ($declarationId <= 0 || ! ($couverture['couvert'] ?? false)) {
            return back()->with('error', 'Aucune demande active à retirer pour aujourd’hui.');
        }

        if ((int) ($couverture['declaration_id'] ?? 0) !== $declarationId) {
            return back()->with('error', 'Cette demande ne couvre pas la journée en cours.');
        }

        $declaration = PointageDeclaration::query()
            ->where('id', $declarationId)
            ->where('user_id', $affectation->user_id)
            ->where('statut', 'valide')
            ->first();

        if ($declaration === null) {
            return back()->with('error', 'Demande introuvable ou déjà retirée.');
        }

        $label = PointageDeclarationTypes::label((string) $declaration->type);

        $declaration->forceFill([
            'statut' => 'rejete',
            'rh_user_id' => $actor->id,
            'rh_decided_at' => now(),
            'rh_comment' => trim((string) ($declaration->rh_comment ? $declaration->rh_comment.' | ' : '').'Retiré par RH depuis l’affectation.'),
        ])->save();

        app(PointageDeclarationPresenceService::class)->retirerApresAnnulationRh($declaration);

        $this->logHistory($affectation->user_id, $actor->id, 'declaration_conge_retiree', [
            'declaration_id' => $declaration->id,
            'type' => $declaration->type,
        ]);
        PointageAuditLog::record(
            $actor,
            'DECLARATION_CONGE_RH_RETIREE',
            $label.' retiré par RH pour '.$affectation->user->name,
            null,
            $request->ip(),
            'ok',
            ['declaration_id' => $declaration->id, 'user_id' => $affectation->user_id]
        );

        return back()->with('success', $label.' retiré : l’employé peut à nouveau être compté en absence si non pointé.');
    }

    public function saveParametrage(Request $request, PointageAffectation $affectation): JsonResponse
    {
        $actor = $this->actor();
        $affectation->syncUserLinkFromProfilEmail();
        if ($affectation->user_id) {
            $this->ensureCanManageUser($actor, $affectation->user);
        }

        $validated = $request->validate([
            'type_pointage' => 'required|string|in:'.implode(',', self::TYPE_POINTAGE),
            'mode_validation' => 'required|string|in:'.implode(',', self::MODE_VALIDATION),
            'date_affectation' => 'nullable|date',
            'date_fin_affectation' => 'nullable|date',
            'statut_activation' => 'required|boolean',
        ]);

        if (
            ! empty($validated['date_affectation'])
            && ! empty($validated['date_fin_affectation'])
            && $validated['date_fin_affectation'] < $validated['date_affectation']
        ) {
            return response()->json([
                'message' => 'La date de fin doit être postérieure ou égale à la date d’affectation.',
                'errors' => ['date_fin_affectation' => ['Incohérence des dates.']],
            ], 422);
        }

        $affectation->update([
            'type_pointage' => $validated['type_pointage'],
            'mode_validation' => $validated['mode_validation'],
            'date_affectation' => $validated['date_affectation'] ?? null,
            'date_fin_affectation' => $validated['date_fin_affectation'] ?? null,
            'statut_activation' => $validated['statut_activation'],
        ]);
        $affectation->syncLegacyUserSettings();

        if ($affectation->user_id) {
            $this->logHistory($affectation->user_id, $actor->id, 'parametrage_enregistre', $validated);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Paramètres d’affectation enregistrés.',
            'settings' => $this->settingsPayloadFromAffectation($affectation),
            'affectation' => $this->affectationPayload($affectation),
            'historique' => $affectation->user_id
                ? $this->historiquePayload($affectation->user_id, 30)
                : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function affectationListItemPayload(PointageAffectation $a): array
    {
        $a->loadMissing('profil', 'user', 'agences');
        $p = $a->profil;

        $agenceAffiche = null;
        $ags = $a->agences->sortBy('nom')->values();
        if ($ags->isNotEmpty()) {
            $principal = $ags->first(fn (Agence $ag) => (bool) ($ag->pivot->is_default ?? false));
            $pick = $principal ?? $ags->first();
            $agenceAffiche = $pick?->nom;
        }
        if ($agenceAffiche === null || trim((string) $agenceAffiche) === '') {
            $agenceAffiche = $p?->site;
        }

        return [
            'id' => $a->id,
            'profil_id' => $a->profil_id,
            'user_id' => $a->user_id,
            'nom' => $p?->nom ?? '—',
            'prenom' => $p?->prenom ?? '',
            'matricule' => $p?->matricule,
            'email' => $p?->email,
            'departement' => $p?->departement,
            'agence' => $agenceAffiche,
            'statut_activation' => $a->statut_activation,
            'type_pointage' => $a->type_pointage,
            'date_affectation' => $a->date_affectation?->format('Y-m-d'),
            'enrolled_at' => $a->enrolled_at?->format('d/m/Y'),
            'has_user_account' => $a->user_id !== null,
        ];
    }

    public function attachAgence(Request $request, PointageAffectation $affectation): JsonResponse
    {
        $actor = $this->actor();
        $affectation->loadMissing('user');
        if ($affectation->user_id) {
            $this->ensureCanManageUser($actor, $affectation->user);
        }

        $validated = $request->validate([
            'agence_id' => 'required|exists:agences,id',
            'date_debut_autorisation' => 'nullable|date',
            'date_fin_autorisation' => 'nullable|date',
            'statut_agence' => 'required|string|in:actif,inactif',
            'niveau_acces' => 'required|string|in:'.implode(',', self::NIVEAU_ACCES),
            'is_default' => 'sometimes|boolean',
        ]);

        $agence = Agence::query()->findOrFail((int) $validated['agence_id']);
        $this->ensureAgenceAssignable($actor, $agence);

        if ($affectation->agences()->where('agences.id', $agence->id)->exists()) {
            return response()->json(['message' => 'Cette agence est déjà autorisée pour cette affectation pointage.'], 422);
        }

        $isDefault = (bool) ($validated['is_default'] ?? false);
        if ($isDefault || $affectation->agences()->count() === 0) {
            $this->clearDefaultFlagsOnAffectation($affectation);
            $isDefault = true;
        }

        $affectation->agences()->attach($agence->id, [
            'is_default' => $isDefault,
            'date_debut_autorisation' => $validated['date_debut_autorisation'] ?? null,
            'date_fin_autorisation' => $validated['date_fin_autorisation'] ?? null,
            'statut_agence' => $validated['statut_agence'],
            'niveau_acces' => $validated['niveau_acces'],
        ]);

        $affectation->syncAgencesToUserPivot();

        $this->logHistory($affectation->user_id, $actor->id, 'agence_ajoutee', [
            'agence_id' => $agence->id,
            'nom' => $agence->nom,
            'affectation_id' => $affectation->id,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Agence autorisée ajoutée.',
            'agences_autorisees' => $this->agencesPayloadFromAffectation($affectation->fresh('agences')),
        ]);
    }

    public function updateAgence(Request $request, PointageAffectation $affectation, Agence $agence): JsonResponse
    {
        $actor = $this->actor();
        $affectation->loadMissing('user');
        if ($affectation->user_id) {
            $this->ensureCanManageUser($actor, $affectation->user);
        }
        $this->ensureAffectationHasAgence($affectation, $agence);

        $validated = $request->validate([
            'date_debut_autorisation' => 'nullable|date',
            'date_fin_autorisation' => 'nullable|date',
            'statut_agence' => 'required|string|in:actif,inactif',
            'niveau_acces' => 'required|string|in:'.implode(',', self::NIVEAU_ACCES),
        ]);

        $affectation->agences()->updateExistingPivot($agence->id, [
            'date_debut_autorisation' => $validated['date_debut_autorisation'] ?? null,
            'date_fin_autorisation' => $validated['date_fin_autorisation'] ?? null,
            'statut_agence' => $validated['statut_agence'],
            'niveau_acces' => $validated['niveau_acces'],
        ]);

        $affectation->syncAgencesToUserPivot();

        $this->logHistory($affectation->user_id, $actor->id, 'agence_modifiee', [
            'agence_id' => $agence->id,
            'nom' => $agence->nom,
            'champs' => $validated,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Droits d’accès mis à jour.',
            'agences_autorisees' => $this->agencesPayloadFromAffectation($affectation->fresh('agences')),
        ]);
    }

    public function detachAgence(Request $request, PointageAffectation $affectation, Agence $agence): JsonResponse
    {
        $actor = $this->actor();
        $affectation->loadMissing('user');
        if ($affectation->user_id) {
            $this->ensureCanManageUser($actor, $affectation->user);
        }
        $this->ensureAffectationHasAgence($affectation, $agence);

        $affectation->agences()->detach($agence->id);

        if ($affectation->agences()->count() === 1) {
            $only = $affectation->agences()->first();
            if ($only) {
                $affectation->agences()->updateExistingPivot($only->id, ['is_default' => true]);
            }
        }

        $affectation->syncAgencesToUserPivot();

        $this->logHistory($affectation->user_id, $actor->id, 'agence_supprimee', [
            'agence_id' => $agence->id,
            'nom' => $agence->nom,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Agence retirée des autorisations pointage.',
            'agences_autorisees' => $this->agencesPayloadFromAffectation($affectation->fresh('agences')),
        ]);
    }

    public function setAgencePrincipale(Request $request, PointageAffectation $affectation, Agence $agence): JsonResponse
    {
        $actor = $this->actor();
        $affectation->loadMissing('user');
        if ($affectation->user_id) {
            $this->ensureCanManageUser($actor, $affectation->user);
        }
        $this->ensureAffectationHasAgence($affectation, $agence);

        DB::transaction(function () use ($affectation, $agence): void {
            $affectation->agences()->newPivotStatement()
                ->where('pointage_affectation_id', $affectation->id)
                ->update(['is_default' => false]);
            $affectation->agences()->updateExistingPivot($agence->id, ['is_default' => true]);
        });

        $affectation->syncAgencesToUserPivot();

        $this->logHistory($affectation->user_id, $actor->id, 'agence_principale', [
            'agence_id' => $agence->id,
            'nom' => $agence->nom,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Agence principale définie.',
            'agences_autorisees' => $this->agencesPayloadFromAffectation($affectation->fresh('agences')),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function typePointageOptions(): array
    {
        return [
            ['value' => 'qr_et_gps', 'label' => 'QR + géolocalisation'],
            ['value' => 'qr_seul', 'label' => 'QR seul'],
            ['value' => 'gps_seul', 'label' => 'Géolocalisation seule'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function modeValidationOptions(): array
    {
        return [
            ['value' => 'validation_manager', 'label' => 'Validation manager'],
            ['value' => 'validation_rh', 'label' => 'Validation RH'],
            ['value' => 'validation_mixte', 'label' => 'Validation mixte'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function niveauAccesOptions(): array
    {
        return [
            ['value' => 'pointage_complet', 'label' => 'Pointage complet'],
            ['value' => 'pointage_limite', 'label' => 'Pointage limité'],
            ['value' => 'consultation', 'label' => 'Consultation'],
        ];
    }

    /**
     * Données pour le formulaire de création de profil dans le module pointage.
     *
     * @return array<string, mixed>
     */
    public static function profilFormOptions(User $actor): array
    {
        $userFilialeId = null;
        if (! $actor->isSuperAdmin()) {
            $userFiliales = $actor->filiales()->get();
            if ($userFiliales->count() > 0) {
                $userFilialeId = $userFiliales->first()->id;
            } elseif ($actor->profil?->filiale_id) {
                $userFilialeId = $actor->profil->filiale_id;
            }
        }

        return [
            'departements' => Departement::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom']),
            'profils' => [],
            'filiales' => Filiale::query()->where('actif', true)->orderBy('nom')->get(['id', 'nom']),
            'user_filiale_id' => $userFilialeId,
            'is_super_admin' => $actor->isSuperAdmin(),
            'next_matricule' => Profil::generateMatricule(),
        ];
    }

    /**
     * Liste légère des profils pour le sélecteur N+1 (chargée en différé, pas dans le HTML initial).
     *
     * @return list<array{id: int, nom: string, prenom: string, matricule: string|null}>
     */
    public static function n1ProfilOptions(User $actor): array
    {
        $profilsQuery = Profil::query()->orderBy('nom')->orderBy('prenom');
        if (! $actor->isSuperAdmin()) {
            $filialeIds = $actor->filiales()->pluck('filiales.id')->all();
            if ($actor->profil?->filiale_id && ! in_array((int) $actor->profil->filiale_id, array_map('intval', $filialeIds), true)) {
                $filialeIds[] = (int) $actor->profil->filiale_id;
            }
            if ($filialeIds !== []) {
                $profilsQuery->whereIn('filiale_id', $filialeIds);
            } else {
                $profilsQuery->whereRaw('1 = 0');
            }
        }

        return $profilsQuery
            ->get(['id', 'nom', 'prenom', 'matricule'])
            ->map(fn (Profil $p) => [
                'id' => (int) $p->id,
                'nom' => (string) $p->nom,
                'prenom' => (string) $p->prenom,
                'matricule' => $p->matricule,
            ])
            ->values()
            ->all();
    }

    /**
     * Agences que le RH peut proposer (périmètre filiales), actives.
     *
     * @return \Illuminate\Support\Collection<int, Agence>
     */
    public static function agencesPickerForActor(User $actor)
    {
        $q = Agence::query()->where('actif', true)->orderBy('nom');
        if ($actor->isSuperAdmin() || $actor->isAdmin()) {
            return $q->get(['id', 'nom', 'code_agent', 'filiale_id']);
        }
        $ids = $actor->filiales()->pluck('filiales.id')->all();
        if ($ids !== []) {
            $q->whereIn('filiale_id', $ids);
        }

        return $q->get(['id', 'nom', 'code_agent', 'filiale_id']);
    }

    private function actor(): User
    {
        $u = Auth::user();
        abort_unless($u && ($u->isRh() || $u->isAdmin() || $u->isSuperAdmin()), 403);

        return $u;
    }

    private function ensureCanManageUser(User $actor, User $target): void
    {
        if ($actor->isAdmin() || $actor->isSuperAdmin()) {
            return;
        }
        abort_unless($actor->isRh() || $actor->isAdmin() || $actor->isSuperAdmin(), 403);
    }

    private function ensureAgenceAssignable(User $actor, Agence $agence): void
    {
        if ($actor->isAdmin() || $actor->isSuperAdmin()) {
            return;
        }
        $ids = $actor->filiales()->pluck('filiales.id')->all();
        if ($ids === [] || $agence->filiale_id === null || ! in_array((int) $agence->filiale_id, array_map('intval', $ids), true)) {
            abort(403, 'Agence hors de votre périmètre.');
        }
    }

    private function ensureAffectationHasAgence(PointageAffectation $affectation, Agence $agence): void
    {
        abort_unless($affectation->agences()->where('agences.id', $agence->id)->exists(), 404);
    }

    private function clearDefaultFlagsOnAffectation(PointageAffectation $affectation): void
    {
        $affectation->agences()->newPivotStatement()
            ->where('pointage_affectation_id', $affectation->id)
            ->update(['is_default' => false]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $agences
     */
    private function syncAgencesOnAffectation(PointageAffectation $affectation, array $agences, User $actor): void
    {
        foreach ($agences as $row) {
            $agenceId = (int) ($row['agence_id'] ?? 0);
            if ($agenceId <= 0) {
                continue;
            }
            if ($affectation->agences()->where('agences.id', $agenceId)->exists()) {
                continue;
            }
            $agence = Agence::query()->find($agenceId);
            if ($agence === null) {
                continue;
            }
            try {
                $this->ensureAgenceAssignable($actor, $agence);
            } catch (\Throwable) {
                continue;
            }
            $isDefault = (bool) ($row['is_default'] ?? false);
            if ($isDefault || $affectation->agences()->count() === 0) {
                $this->clearDefaultFlagsOnAffectation($affectation);
                $isDefault = true;
            }
            $affectation->agences()->attach($agenceId, [
                'is_default' => $isDefault,
                'date_debut_autorisation' => $row['date_debut_autorisation'] ?? null,
                'date_fin_autorisation' => $row['date_fin_autorisation'] ?? null,
                'statut_agence' => $row['statut_agence'] ?? 'actif',
                'niveau_acces' => $row['niveau_acces'] ?? 'pointage_complet',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profilPayload(Profil $profil): array
    {
        return [
            'id' => $profil->id,
            'matricule' => $profil->matricule,
            'nom' => $profil->nom,
            'prenom' => $profil->prenom,
            'fonction' => $profil->fonction,
            'departement' => $profil->departement,
            'service' => $profil->departement,
            'email' => $profil->email,
            'telephone' => $profil->telephone,
            'statut' => $profil->statut,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function settingsPayload(User $user): ?array
    {
        $user->loadMissing('pointageAffectationSetting');
        $s = $user->pointageAffectationSetting;
        if ($s === null) {
            return null;
        }

        return [
            'type_pointage' => $s->type_pointage,
            'mode_validation' => $s->mode_validation,
            'date_affectation' => $s->date_affectation?->format('Y-m-d'),
            'date_fin_affectation' => $s->date_fin_affectation?->format('Y-m-d'),
            'statut_activation' => $s->statut_activation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayloadFromAffectation(PointageAffectation $a): array
    {
        return [
            'type_pointage' => $a->type_pointage,
            'mode_validation' => $a->mode_validation,
            'date_affectation' => $a->date_affectation?->format('Y-m-d'),
            'date_fin_affectation' => $a->date_fin_affectation?->format('Y-m-d'),
            'statut_activation' => $a->statut_activation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function affectationPayload(PointageAffectation $a): array
    {
        return [
            'id' => $a->id,
            'profil_id' => $a->profil_id,
            'user_id' => $a->user_id,
            'statut_activation' => $a->statut_activation,
            'enrolled_at' => $a->enrolled_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function agencesPayloadFromAffectation(PointageAffectation $affectation): array
    {
        $affectation->load(['agences' => function ($q) {
            $q->orderBy('nom');
        }]);

        $out = [];
        foreach ($affectation->agences as $ag) {
            $p = $ag->pivot;
            $out[] = [
                'id' => $ag->id,
                'code_agent' => $ag->code_agent,
                'nom' => $ag->nom,
                'date_debut_autorisation' => $p->date_debut_autorisation
                    ? Carbon::parse($p->date_debut_autorisation)->format('Y-m-d')
                    : null,
                'date_fin_autorisation' => $p->date_fin_autorisation
                    ? Carbon::parse($p->date_fin_autorisation)->format('Y-m-d')
                    : null,
                'statut_agence' => $p->statut_agence ?? 'actif',
                'niveau_acces' => $p->niveau_acces ?? 'pointage_complet',
                'is_default' => (bool) ($p->is_default ?? false),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function historiquePayload(int $userId, int $limit): array
    {
        return PointageAffectationHistory::query()
            ->where('user_id', $userId)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (PointageAffectationHistory $h) => [
                'id' => $h->id,
                'action' => $h->action,
                'payload' => $h->payload,
                'actor' => $h->actor?->name,
                'created_at' => $h->created_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    private function logHistory(?int $userId, int $actorId, string $action, ?array $payload = null): void
    {
        if ($userId === null) {
            return;
        }
        try {
            PointageAffectationHistory::query()->create([
                'user_id' => $userId,
                'actor_id' => $actorId,
                'action' => $action,
                'payload' => $payload,
            ]);
        } catch (QueryException $e) {
            Log::warning('Pointage affectation: écriture historique impossible (exécuter les migrations ?).', ['exception' => $e->getMessage()]);
        }
    }
}
