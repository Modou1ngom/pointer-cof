<?php

namespace App\Http\Controllers;

use App\Models\Agence;
use App\Models\Filiale;
use App\Models\PointageAuditLog;
use App\Models\Profil;
use App\Models\User;
use App\Services\Pointage\AgenceEmployesEnrollementService;
use App\Services\PointageQrService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PointageSiteController extends Controller
{
    public function index(Request $request, PointageQrService $qrService, AgenceEmployesEnrollementService $employesService)
    {
        $user = Auth::user();
        $query = Agence::query()
            ->enrolledForPointageQr()
            ->with(['filiale:id,nom'])
            ->orderBy('nom');

        if ($user && ! $user->isSuperAdmin()) {
            $ids = $user->filiales()->pluck('filiales.id')->all();
            if (! empty($ids)) {
                $query->whereIn('filiale_id', $ids);
            }
        }

        $codeFilter = $request->query('code_agence');
        $codeAgence = '';
        if (is_string($codeFilter)) {
            $codeAgence = trim($codeFilter);
            if ($codeAgence !== '') {
                $query->where(function ($q) use ($codeAgence) {
                    $q->where('code_agent', $codeAgence)
                        ->orWhere('nom', 'like', '%'.$codeAgence.'%');
                });
            }
        }

        $perPage = (int) $request->get('per_page', 50);
        $agences = $query->paginate(max(5, min(100, $perPage)));

        $qrPreview = [];
        foreach ($agences->items() as $agence) {
            // Instance DB « propre » : sans attributs calculés, sinon save() (QR secret) persisterait des colonnes inexistantes.
            $agenceClean = Agence::query()->findOrFail($agence->id);
            $agenceClean->ensureKioskToken();
            $agence->pointage_kiosk_token = $agenceClean->pointage_kiosk_token;
            $qrPreview[$agence->id] = $qrService->mintToken($agenceClean);
        }

        $employesCounts = $employesService->countEmployesEnrolesPourAgenceIds(
            collect($agences->items())->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $agences->through(function (Agence $agence) use ($employesCounts) {
            $employesCount = $employesCounts[$agence->id] ?? 0;
            $adresseCourte = $agence->description
                ? (mb_strlen($agence->description) > 120 ? mb_substr($agence->description, 0, 117).'…' : $agence->description)
                : null;

            $payload = array_merge($agence->toArray(), [
                'employes_count' => $employesCount,
                'adresse_courte' => $adresseCourte,
                'region_label' => $agence->filiale?->nom,
                'is_virtual' => (bool) $agence->is_virtual,
                'parent_agence_id' => $agence->parent_agence_id,
                'kiosk_url' => $agence->pointage_kiosk_token
                    ? route('pointage.kiosk.show', ['token' => $agence->pointage_kiosk_token])
                    : null,
            ]);
            unset($payload['pointage_qr_secret'], $payload['pointage_kiosk_token']);

            return $payload;
        });

        return Inertia::render('Pointage/sites/Index', [
            'agences' => $agences,
            'qrPreview' => $qrPreview,
            'canRegenerateAllQr' => (bool) ($user && ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin())),
            'filters' => [
                'code_agence' => $codeAgence,
            ],
        ]);
    }

    public function create(AgenceEmployesEnrollementService $employesService)
    {
        $filiales = Filiale::where('actif', true)->orderBy('nom')->get(['id', 'nom']);

        $nomsAgencesExistants = Agence::query()->pluck('nom')->all();

        $agencesExistantes = Agence::query()
            ->enrolledForPointageQr()
            ->where('is_virtual', false)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code_agent', 'filiale_id']);

        $sitesDepuisProfils = Profil::query()
            ->whereNotNull('site')
            ->where('site', '!=', '')
            ->orderBy('site')
            ->get(['id', 'site', 'filiale_id', 'nom', 'prenom', 'matricule'])
            ->groupBy('site')
            ->map(function ($profils, string $site) use ($nomsAgencesExistants, $employesService) {
                if (in_array($site, $nomsAgencesExistants, true)) {
                    return null;
                }
                $first = $profils->first();

                return [
                    'site' => $site,
                    'nom' => $site,
                    'code_agent' => '',
                    'filiale_id' => $first->filiale_id,
                    'latitude' => null,
                    'longitude' => null,
                    'profils_count' => $profils->count(),
                    'employes_enroles_count' => $employesService->countEmployesEnrolesPourSiteNom($site),
                    'echantillon' => $profils->take(2)->map(fn (Profil $p) => trim($p->prenom.' '.$p->nom))->implode(', '),
                ];
            })
            ->filter()
            ->values()
            ->sortBy('nom')
            ->values()
            ->all();

        return Inertia::render('Pointage/sites/Create', [
            'filiales' => $filiales,
            'sitesDepuisProfils' => $sitesDepuisProfils,
            'agencesExistantes' => $agencesExistantes,
        ]);
    }

    public function store(Request $request, AgenceEmployesEnrollementService $employesService)
    {
        $request->merge($this->normalizedGpsInput($request));

        $validated = $request->validate([
            'nom' => 'required|string|max:255|unique:agences,nom',
            'code_agent' => 'nullable|string|max:50|unique:agences,code_agent',
            'description' => 'nullable|string',
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'rayon_geofencing_metres' => 'nullable|integer|min:10|max:2000',
            'pointage_qr_type' => 'required|in:dynamic,static',
            'is_virtual' => 'nullable|boolean',
            'parent_agence_id' => 'nullable|integer|exists:agences,id',
            'actif' => 'required|in:actif,inactif',
            'chef_agence_id' => 'nullable|exists:profiles,id',
            'filiale_id' => 'nullable|exists:filiales,id',
        ]);

        $isVirtual = (bool) ($validated['is_virtual'] ?? false);

        $agence = Agence::create([
            'nom' => $validated['nom'],
            'code_agent' => $validated['code_agent'] ?? mb_substr(uniqid('AG_', true), 0, 50),
            'description' => $validated['description'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'rayon_geofencing_metres' => $validated['rayon_geofencing_metres'] ?? 50,
            'pointage_qr_type' => $isVirtual ? 'static' : $validated['pointage_qr_type'],
            'pointage_qr_secret' => bin2hex(random_bytes(32)),
            'pointage_qr_enrolled_at' => now(),
            'pointage_kiosk_token' => bin2hex(random_bytes(24)),
            'actif' => $validated['actif'] === 'actif',
            'is_virtual' => $isVirtual,
            'parent_agence_id' => $isVirtual ? ($validated['parent_agence_id'] ?? null) : null,
            'chef_agence_id' => $validated['chef_agence_id'] ?? null,
            'filiale_id' => $validated['filiale_id'] ?? null,
        ]);

        $employesSync = $employesService->syncEmployesEnrolesPourAgence($agence);

        PointageAuditLog::record(
            Auth::user(),
            'QR_ENROLEMENT',
            'Enrôlement site pointage QR — '.$agence->nom,
            $agence,
            $request->ip(),
            'ok',
            ['employes_synchronises' => $employesSync]
        );

        $message = 'Site / agence enrôlé au pointage QR.';
        if ($employesSync > 0) {
            $message .= ' '.$employesSync.' employé(s) enrôlé(s) rattaché(s) à cette agence.';
        }

        return redirect()->route('pointage.sites.index')
            ->with('success', $message);
    }

    public function edit(Agence $site)
    {
        abort_unless($site->isEnrolledForPointageQr(), 404);

        $profils = Profil::orderBy('nom')->get(['id', 'nom', 'prenom', 'matricule']);
        $filiales = Filiale::where('actif', true)->orderBy('nom')->get(['id', 'nom']);

        return Inertia::render('Pointage/sites/Edit', [
            'agence' => $site,
            'profils' => $profils,
            'filiales' => $filiales,
        ]);
    }

    public function update(Request $request, Agence $site)
    {
        abort_unless($site->isEnrolledForPointageQr(), 404);

        $request->merge($this->normalizedGpsInput($request));

        $validated = $request->validate([
            'nom' => 'required|string|max:255|unique:agences,nom,'.$site->id,
            'code_agent' => 'nullable|string|max:50|unique:agences,code_agent,'.$site->id,
            'description' => 'nullable|string',
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'rayon_geofencing_metres' => 'nullable|integer|min:10|max:2000',
            'pointage_qr_type' => 'required|in:dynamic,static',
            'is_virtual' => 'nullable|boolean',
            'parent_agence_id' => 'nullable|integer|exists:agences,id',
            'kiosk_serial_number' => 'nullable|string|max:128',
            'clear_kiosk_serial' => 'nullable|boolean',
            'actif' => 'required|in:actif,inactif',
            'chef_agence_id' => 'nullable|exists:profiles,id',
            'filiale_id' => 'nullable|exists:filiales,id',
        ]);

        $isVirtual = (bool) ($validated['is_virtual'] ?? $site->is_virtual);

        $kioskSerial = $site->kiosk_serial_number;
        if (! empty($validated['clear_kiosk_serial'])) {
            $kioskSerial = null;
        } elseif (array_key_exists('kiosk_serial_number', $validated) && $validated['kiosk_serial_number'] !== null) {
            $kioskSerial = \App\Support\MobileDeviceId::normalize((string) $validated['kiosk_serial_number']) ?: null;
        }

        $site->update([
            'nom' => $validated['nom'],
            'code_agent' => $validated['code_agent'] ?? $site->code_agent,
            'description' => $validated['description'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'rayon_geofencing_metres' => $validated['rayon_geofencing_metres'] ?? $site->rayon_geofencing_metres,
            'pointage_qr_type' => $isVirtual ? 'static' : $validated['pointage_qr_type'],
            'actif' => $validated['actif'] === 'actif',
            'is_virtual' => $isVirtual,
            'kiosk_serial_number' => $isVirtual ? $kioskSerial : null,
            'parent_agence_id' => $isVirtual
                ? ($validated['parent_agence_id'] ?? $site->parent_agence_id)
                : null,
            'chef_agence_id' => $validated['chef_agence_id'] ?? null,
            'filiale_id' => $validated['filiale_id'] ?? null,
        ]);

        return redirect()->route('pointage.sites.index')
            ->with('success', 'Site mis à jour.');
    }

    /**
     * Crée une agence virtuelle liée à une agence réelle (QR static, pointage par matricule).
     */
    public function storeVirtual(Request $request, Agence $site, AgenceEmployesEnrollementService $employesService)
    {
        abort_unless($site->isEnrolledForPointageQr(), 404);
        abort_if($site->isVirtual(), 422, 'Impossible de créer une virtuelle depuis une agence déjà virtuelle.');

        $validated = $request->validate([
            'nom' => 'nullable|string|max:255|unique:agences,nom',
            'code_agent' => 'nullable|string|max:50|unique:agences,code_agent',
        ]);

        $nom = trim((string) ($validated['nom'] ?? ''));
        if ($nom === '') {
            $nom = $site->nom.' — Virtuelle';
            $base = $nom;
            $i = 2;
            while (Agence::query()->where('nom', $nom)->exists()) {
                $nom = $base.' '.$i;
                $i++;
            }
        }

        $code = trim((string) ($validated['code_agent'] ?? ''));
        if ($code === '') {
            $code = mb_substr(($site->code_agent ?: 'AG').'-V'.bin2hex(random_bytes(2)), 0, 50);
        }

        $agence = Agence::create([
            'nom' => $nom,
            'code_agent' => $code,
            'description' => 'Agence virtuelle (borne partagée, e-mail + OTP) liée à '.$site->nom,
            'latitude' => $site->latitude,
            'longitude' => $site->longitude,
            'rayon_geofencing_metres' => $site->rayon_geofencing_metres ?? 50,
            'pointage_qr_type' => 'static',
            'pointage_qr_secret' => bin2hex(random_bytes(32)),
            'pointage_qr_enrolled_at' => now(),
            'pointage_qr_enabled' => true,
            'pointage_kiosk_token' => bin2hex(random_bytes(24)),
            'actif' => true,
            'is_virtual' => true,
            'parent_agence_id' => $site->id,
            'chef_agence_id' => $site->chef_agence_id,
            'filiale_id' => $site->filiale_id,
        ]);

        $employesSync = $employesService->syncEmployesEnrolesPourAgence($agence);

        PointageAuditLog::record(
            Auth::user(),
            'QR_ENROLEMENT',
            'Création agence virtuelle — '.$agence->nom,
            $agence,
            $request->ip(),
            'ok',
            [
                'parent_agence_id' => $site->id,
                'employes_synchronises' => $employesSync,
            ]
        );

        return redirect()->route('pointage.sites.index')
            ->with('success', 'Agence virtuelle créée : '.$agence->nom.'. QR statique prêt pour la borne.');
    }

    public function destroy(Agence $site)
    {
        abort_unless($site->isEnrolledForPointageQr(), 404);

        $site->delete();

        return redirect()->route('pointage.sites.index')
            ->with('success', 'Site supprimé.');
    }

    /**
     * Mise à jour rapide des rayons de géorepérage (tests / paramétrage).
     */
    public function updateRayons(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'rayons' => 'required|array|min:1',
            'rayons.*.id' => 'required|integer|exists:agences,id',
            'rayons.*.rayon_geofencing_metres' => 'required|integer|min:10|max:2000',
        ]);

        $updated = 0;
        foreach ($validated['rayons'] as $row) {
            $agence = Agence::query()->find($row['id']);
            if (! $agence || ! $agence->isEnrolledForPointageQr()) {
                continue;
            }
            if (! $this->userCanAccessAgence(Auth::user(), $agence)) {
                continue;
            }
            $agence->update([
                'rayon_geofencing_metres' => (int) $row['rayon_geofencing_metres'],
            ]);
            $updated++;
        }

        return back()->with(
            'success',
            $updated > 0
                ? "Rayon de géorepérage mis à jour pour {$updated} site(s)."
                : 'Aucun site mis à jour.'
        );
    }

    public function regenererQr(Request $request, Agence $site, PointageQrService $qr): \Illuminate\Http\RedirectResponse
    {
        abort_unless($site->isEnrolledForPointageQr(), 404);

        $site->pointage_qr_secret = bin2hex(random_bytes(32));
        $site->ensureKioskToken(false);
        $site->save();
        $qr->mintToken($site);

        PointageAuditLog::record(Auth::user(), 'QR_REGENERE', 'QR régénéré — '.$site->nom, $site, $request->ip(), 'ok');

        return back()->with('success', 'QR régénéré.');
    }

    public function regenererLienKiosk(Request $request, Agence $site): \Illuminate\Http\RedirectResponse
    {
        abort_unless($site->isEnrolledForPointageQr(), 404);
        abort_unless($this->userCanAccessAgence(Auth::user(), $site), 403);

        $site->regenerateKioskToken();

        PointageAuditLog::record(
            Auth::user(),
            'KIOSK_LIEN_REGENERE',
            'Lien borne / tablette régénéré — '.$site->nom,
            $site,
            $request->ip(),
            'ok'
        );

        return back()->with('success', 'Nouveau lien tablette généré. Rouvrez l’URL sur la borne.');
    }

    public function regenererTousQr(Request $request, PointageQrService $qr): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin()), 403);

        $sites = Agence::query()->enrolledForPointageQr()->orderBy('nom')->get();
        foreach ($sites as $site) {
            $site->pointage_qr_secret = bin2hex(random_bytes(32));
            $site->save();
            $qr->mintToken($site);
        }

        PointageAuditLog::record(
            Auth::user(),
            'QR_REGENERE_TOUS',
            'Régénération QR tous les sites ('.$sites->count().')',
            null,
            $request->ip(),
            'ok',
            ['sites' => $sites->pluck('id')->all()]
        );

        return back()->with('success', 'Tous les secrets QR ont été régénérés.');
    }

    public function toggleActif(Request $request, Agence $site): \Illuminate\Http\RedirectResponse
    {
        $site->actif = ! $site->actif;
        $site->save();

        PointageAuditLog::record(
            Auth::user(),
            $site->actif ? 'SITE_ACTIVE' : 'SITE_PAUSE',
            ($site->actif ? 'Activation site — ' : 'Pause site — ').$site->nom,
            $site,
            $request->ip(),
            'ok'
        );

        return back()->with('success', $site->actif ? 'Site activé.' : 'Site mis en pause.');
    }

    /**
     * Recherche d’agence par code (ou nom partiel) pour le module « Génération QR Code ».
     */
    public function lookupQrParCode(Request $request, PointageQrService $qr): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin()), 403);

        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['agence' => null, 'message' => 'Saisissez un code agence.']);
        }

        $query = Agence::query()->with(['filiale:id,nom']);
        if (! $user->isSuperAdmin()) {
            $ids = $user->filiales()->pluck('filiales.id')->all();
            if ($ids !== []) {
                $query->whereIn('filiale_id', $ids);
            }
        }

        $agence = $query->where(function ($q) use ($code) {
            $q->where('code_agent', $code)
                ->orWhere('nom', 'like', '%'.$code.'%');
        })->first();

        if ($agence === null) {
            return response()->json(['agence' => null, 'message' => 'Aucune agence correspondante.']);
        }

        abort_unless($this->userCanAccessAgence($user, $agence), 403);

        return response()->json([
            'agence' => $this->agenceQrPayload($agence),
            'qr_preview' => $qr->mintToken($agence),
        ]);
    }

    /**
     * Enregistre la configuration QR (dates, plage, type) et optionnellement régénère le secret.
     */
    public function updateQrConfiguration(Request $request, Agence $site, PointageQrService $qr, AgenceEmployesEnrollementService $employesService): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        abort_unless($this->userCanAccessAgence($user, $site), 403);

        $validated = $request->validate([
            'pointage_qr_activated_on' => 'nullable|date',
            'pointage_qr_expires_on' => 'nullable|date',
            'pointage_qr_type' => 'required|in:dynamic,static',
            'pointage_plage_debut' => 'nullable|date_format:H:i',
            'pointage_plage_fin' => 'nullable|date_format:H:i',
            'pointage_qr_enabled' => 'sometimes|boolean',
            'regenerate_secret' => 'sometimes|boolean',
        ]);

        if (
            ! empty($validated['pointage_qr_activated_on'])
            && ! empty($validated['pointage_qr_expires_on'])
            && $validated['pointage_qr_expires_on'] < $validated['pointage_qr_activated_on']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pointage_qr_expires_on' => ['La date d’expiration doit être postérieure ou égale à la date d’activation.'],
            ]);
        }

        $site->pointage_qr_activated_on = $validated['pointage_qr_activated_on'] ?? null;
        $site->pointage_qr_expires_on = $validated['pointage_qr_expires_on'] ?? null;
        $site->pointage_plage_debut = $validated['pointage_plage_debut'] ?? null;
        $site->pointage_plage_fin = $validated['pointage_plage_fin'] ?? null;
        $site->pointage_qr_type = $validated['pointage_qr_type'];

        if (array_key_exists('pointage_qr_enabled', $validated)) {
            $site->pointage_qr_enabled = (bool) $validated['pointage_qr_enabled'];
        }

        $wasEnrolled = $site->isEnrolledForPointageQr();

        if ($request->boolean('regenerate_secret') || ! $site->pointage_qr_secret) {
            $site->pointage_qr_secret = bin2hex(random_bytes(32));
        }

        $site->markEnrolledForPointageQr();
        $site->save();

        $employesSync = 0;
        if (! $wasEnrolled) {
            $employesSync = $employesService->syncEmployesEnrolesPourAgence($site);
        }

        PointageAuditLog::record(
            Auth::user(),
            $wasEnrolled ? 'QR_CONFIGURATION' : 'QR_ENROLEMENT',
            'Configuration QR — '.$site->nom,
            $site,
            $request->ip(),
            'ok',
            [
                'regenerate' => $request->boolean('regenerate_secret'),
                'employes_synchronises' => $employesSync,
            ]
        );

        if ($request->expectsJson()) {
            $site->refresh()->load('filiale:id,nom');

            return response()->json([
                'message' => $request->boolean('regenerate_secret')
                    ? 'Configuration enregistrée et nouveau QR généré.'
                    : 'Configuration enregistrée.',
                'agence' => $this->agenceQrPayload($site),
                'qr_preview' => $qr->mintToken($site),
            ]);
        }

        return back()->with('success', 'Configuration QR enregistrée.');
    }

    public function desactiverQr(Request $request, Agence $site): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        abort_unless($site->isEnrolledForPointageQr(), 404);
        abort_unless($this->userCanAccessAgence($user, $site), 403);

        $site->pointage_qr_enabled = false;
        $site->save();

        PointageAuditLog::record(Auth::user(), 'QR_DESACTIVE', 'QR désactivé — '.$site->nom, $site, $request->ip(), 'ok');

        if ($request->expectsJson()) {
            $site->refresh()->load('filiale:id,nom');

            return response()->json([
                'message' => 'QR Code désactivé pour cette agence.',
                'agence' => $this->agenceQrPayload($site),
            ]);
        }

        return back()->with('success', 'QR Code désactivé.');
    }

    private function userCanAccessAgence(?User $user, Agence $agence): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }
        if (! ($user->isRh() || $user->isAdmin())) {
            return false;
        }
        $ids = $user->filiales()->pluck('filiales.id')->all();
        if ($ids === []) {
            return true;
        }

        return $agence->filiale_id !== null
            && in_array((int) $agence->filiale_id, array_map('intval', $ids), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function agenceQrPayload(Agence $agence): array
    {
        $agence->loadMissing('filiale:id,nom');

        return [
            'id' => $agence->id,
            'code_agent' => $agence->code_agent,
            'nom' => $agence->nom,
            'region_label' => $agence->filiale?->nom,
            'adresse' => $agence->description,
            'actif' => (bool) $agence->actif,
            'pointage_qr_type' => $agence->pointage_qr_type,
            'pointage_qr_activated_on' => $agence->pointage_qr_activated_on?->format('Y-m-d'),
            'pointage_qr_expires_on' => $agence->pointage_qr_expires_on?->format('Y-m-d'),
            'pointage_plage_debut' => $this->formatTimeHm($agence->pointage_plage_debut),
            'pointage_plage_fin' => $this->formatTimeHm($agence->pointage_plage_fin),
            'pointage_qr_enabled' => (bool) ($agence->pointage_qr_enabled ?? true),
            'is_enrolled' => $agence->isEnrolledForPointageQr(),
            'pointage_qr_enrolled_at' => $agence->pointage_qr_enrolled_at?->toIso8601String(),
            'kiosk_url' => $agence->ensureKioskToken() ? $agence->kioskUrl() : null,
        ];
    }

    private function formatTimeHm(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{latitude: float|null, longitude: float|null}
     */
    private function normalizedGpsInput(Request $request): array
    {
        $lat = $request->input('latitude');
        $lng = $request->input('longitude');
        $latNull = $lat === null || $lat === '';
        $lngNull = $lng === null || $lng === '';

        if ($latNull && $lngNull) {
            return ['latitude' => null, 'longitude' => null];
        }

        return [
            'latitude' => is_numeric($lat) ? (float) $lat : null,
            'longitude' => is_numeric($lng) ? (float) $lng : null,
        ];
    }
}
