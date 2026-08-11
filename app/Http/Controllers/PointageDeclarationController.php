<?php

namespace App\Http\Controllers;

use App\Models\Departement;
use App\Models\PointageAuditLog;
use App\Models\PointageDeclaration;
use App\Models\Profil;
use App\Services\Pointage\PointageDeclarationPresenceService;
use App\Support\PointageDeclarationTypes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PointageDeclarationController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $user->profilCollaborateurAssocie();

        $query = PointageDeclaration::query()->with([
            'user:id,name,email',
            'managerUser:id,name',
            'rhUser:id,name',
        ]);

        $voirToutes = $request->boolean('toutes')
            && $user
            && ($user->isSuperAdmin() || $user->isAdmin() || $user->isRh());

        if (! $voirToutes && $user) {
            $query->where('user_id', $user->id);
        }

        $mois = $request->input('mois', Carbon::now()->format('Y-m'));
        if (! is_string($mois) || ! preg_match('/^\d{4}-\d{2}$/', $mois)) {
            $mois = Carbon::now()->format('Y-m');
        }
        [$year, $month] = array_map('intval', explode('-', $mois, 2));
        $query->whereYear('date_concernee', $year)->whereMonth('date_concernee', $month);

        $declarations = $query->orderByDesc('date_concernee')->orderByDesc('created_at')->paginate(20)->withQueryString();

        $declarations->setCollection(
            $declarations->getCollection()->map(fn (PointageDeclaration $d) => $this->serializeDeclarationRow($d))
        );

        $meta = $this->declarationFlowMetaForUser($user);

        $listQuery = ['mois' => $mois];
        if ($voirToutes) {
            $listQuery['toutes'] = '1';
        }

        return Inertia::render('pointage/DeclarationsIndex', [
            'declarations' => $declarations,
            'periode_mois' => $mois,
            'periode_label' => $this->frenchMonthYearLabel($mois),
            'validation_hint' => $meta['validation_hint'],
            'declarationListQuery' => $listQuery,
        ]);
    }

    public function create()
    {
        $user = Auth::user();
        $meta = $this->declarationFlowMetaForUser($user);

        return Inertia::render('pointage/DeclarationsCreate', [
            'manager_nom' => $meta['manager_nom'],
            'validation_hint' => $meta['validation_hint'],
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();
        $type = PointageDeclarationTypes::normalize((string) $request->input('type', ''));
        $request->merge(['type' => $type]);
        $validated = $request->validate(PointageDeclarationTypes::storeRules($type));

        $path = null;
        if ($request->hasFile('justificatif')) {
            $path = $request->file('justificatif')->store('pointage_declarations', 'local');
        }

        $user->profilCollaborateurAssocie();
        $profil = $user->profil;
        $statut = ($profil && $profil->n_plus_1_id) ? 'en_attente_manager' : 'en_attente_rh';

        PointageDeclaration::create([
            'user_id' => $user->id,
            'type' => $type,
            'date_concernee' => $validated['date_concernee'],
            'date_fin' => $validated['date_fin'] ?? null,
            'heure_debut' => $validated['heure_debut'] ?? null,
            'heure_fin' => $validated['heure_fin'] ?? null,
            'lieu' => $validated['lieu'] ?? null,
            'motif' => $validated['motif'],
            'commentaire' => $validated['commentaire'] ?? null,
            'justificatif_path' => $path,
            'statut' => $statut,
        ]);

        PointageAuditLog::record($user, 'DECLARATION_SOUMISE', 'Nouvelle déclaration pointage', null, $request->ip(), 'ok');

        $msg = $statut === 'en_attente_manager'
            ? 'Déclaration envoyée à votre N+1 pour validation.'
            : 'Déclaration envoyée au RH pour validation.';

        return redirect()->route('pointage.declarations.index')->with('success', $msg);
    }

    public function demande(Request $request)
    {
        return $this->regulation($request);
    }

    public function regulation(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin()), 403);

        $mois = $request->input('mois', Carbon::now()->format('Y-m'));
        if (! is_string($mois) || ! preg_match('/^\d{4}-\d{2}$/', $mois)) {
            $mois = Carbon::now()->format('Y-m');
        }
        [$year, $month] = array_map('intval', explode('-', $mois, 2));

        $query = PointageDeclaration::query()
            ->with(['user:id,name,email', 'user.profil:id,email,fonction', 'managerUser:id,name', 'rhUser:id,name'])
            ->where(function ($q) use ($year, $month): void {
                $q->where(function ($w) use ($year, $month): void {
                    $w->whereYear('date_concernee', $year)->whereMonth('date_concernee', $month);
                });
                if (\Illuminate\Support\Facades\Schema::hasColumn('pointage_declarations', 'date_fin')) {
                    $q->orWhere(function ($w) use ($year, $month): void {
                        $w->whereNotNull('date_fin')
                            ->whereYear('date_fin', $year)
                            ->whereMonth('date_fin', $month);
                    });
                }
            });

        $onglet = $request->input('onglet', 'attente');
        if (! is_string($onglet) || ! in_array($onglet, ['attente', 'historique', 'toutes'], true)) {
            $onglet = 'attente';
        }

        if ($onglet === 'attente') {
            $query->whereIn('statut', ['en_attente_manager', 'en_attente_rh']);
        } elseif ($onglet === 'historique') {
            $query->whereIn('statut', ['valide', 'rejete']);
        }

        $type = $request->input('type');
        if (is_string($type) && $type !== '' && $type !== 'tous') {
            $query->where('type', $type);
        }

        $statut = $request->input('statut');
        if ($onglet === 'toutes' && is_string($statut) && $statut !== '' && $statut !== 'tous') {
            $query->where('statut', $statut);
        }

        $q = $request->input('q');
        if (is_string($q) && trim($q) !== '') {
            $term = '%'.trim($q).'%';
            $query->whereHas('user', function ($u) use ($term): void {
                $u->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }

        $declarations = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(30)->withQueryString();
        $declarations->setCollection(
            $declarations->getCollection()->map(fn (PointageDeclaration $d) => $this->serializeDeclarationRow($d))
        );

        $countBase = function () use ($year, $month) {
            return PointageDeclaration::query()
                ->where(function ($q) use ($year, $month): void {
                    $q->where(function ($w) use ($year, $month): void {
                        $w->whereYear('date_concernee', $year)->whereMonth('date_concernee', $month);
                    });
                    if (\Illuminate\Support\Facades\Schema::hasColumn('pointage_declarations', 'date_fin')) {
                        $q->orWhere(function ($w) use ($year, $month): void {
                            $w->whereNotNull('date_fin')
                                ->whereYear('date_fin', $year)
                                ->whereMonth('date_fin', $month);
                        });
                    }
                });
        };

        $counts = [
            'en_attente_manager' => $countBase()->where('statut', 'en_attente_manager')->count(),
            'en_attente_rh' => $countBase()->where('statut', 'en_attente_rh')->count(),
            'valide' => $countBase()->where('statut', 'valide')->count(),
            'rejete' => $countBase()->where('statut', 'rejete')->count(),
        ];
        // Badge onglet « En attente de Validation »
        $counts['en_attente'] = (int) $counts['en_attente_manager'] + (int) $counts['en_attente_rh'];


        $historique = PointageDeclaration::query()
            ->with(['user:id,name,email', 'user.profil:id,email,fonction', 'managerUser:id,name', 'rhUser:id,name'])
            ->whereIn('statut', ['valide', 'rejete'])
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get()
            ->map(fn (PointageDeclaration $d) => $this->serializeDeclarationRow($d))
            ->values();

        return Inertia::render('Pointage/Demande', [
            'declarations' => $declarations,
            'historique' => $historique,
            'periode_mois' => $mois,
            'periode_label' => $this->frenchMonthYearLabel($mois),
            'filters' => [
                'type' => is_string($type) && $type !== '' ? $type : 'tous',
                'statut' => is_string($statut) && $statut !== '' ? $statut : 'tous',
                'q' => is_string($q) ? $q : '',
                'onglet' => $onglet,
            ],
            'types' => PointageDeclarationTypes::optionsForApi(),
            'counts' => $counts,
            'can_manage' => (bool) $user->isSuperAdmin(),
            'can_validate_rh' => (bool) ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin()),
        ]);
    }

    public function update(Request $request, PointageDeclaration $declaration)
    {
        $user = Auth::user();
        abort_unless($user && $user->isSuperAdmin(), 403);

        $type = PointageDeclarationTypes::normalize((string) $request->input('type', $declaration->type));
        $request->merge(['type' => $type]);
        $validated = $request->validate(array_merge(
            PointageDeclarationTypes::storeRules($type),
            [
                'statut' => 'nullable|in:en_attente_manager,en_attente_rh,valide,rejete',
            ]
        ));

        if ($request->hasFile('justificatif')) {
            $path = $request->file('justificatif')->store('pointage_declarations', 'local');
            $declaration->justificatif_path = $path;
        }

        $wasValide = $declaration->statut === 'valide';

        $declaration->fill([
            'type' => $type,
            'date_concernee' => $validated['date_concernee'],
            'date_fin' => $validated['date_fin'] ?? null,
            'heure_debut' => $validated['heure_debut'] ?? null,
            'heure_fin' => $validated['heure_fin'] ?? null,
            'lieu' => $validated['lieu'] ?? null,
            'motif' => $validated['motif'],
            'commentaire' => $validated['commentaire'] ?? null,
        ]);

        if (isset($validated['statut']) && is_string($validated['statut'])) {
            $declaration->statut = $validated['statut'];
        }

        $declaration->save();

        if (! $wasValide && $declaration->statut === 'valide') {
            app(PointageDeclarationPresenceService::class)->appliquerApresValidationRh($declaration->fresh());
        }

        PointageAuditLog::record(
            $user,
            'DECLARATION_MAJ_SUPERADMIN',
            'Déclaration modifiée (demande)',
            null,
            $request->ip(),
            'ok',
            ['declaration_id' => $declaration->id]
        );

        return back()->with('success', 'Déclaration mise à jour.');
    }

    public function destroy(Request $request, PointageDeclaration $declaration)
    {
        $user = Auth::user();
        abort_unless($user && $user->isSuperAdmin(), 403);

        $id = $declaration->id;
        $declaration->delete();

        PointageAuditLog::record(
            $user,
            'DECLARATION_SUPP_SUPERADMIN',
            'Déclaration supprimée (régulation)',
            null,
            $request->ip(),
            'alerte',
            ['declaration_id' => $id]
        );

        return back()->with('success', 'Déclaration supprimée.');
    }

    public function validationManager(Request $request)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isResponsableDepartement() || $user->isAdmin() || $user->isRh() || $user->isSuperAdmin()), 403);

        $pending = PointageDeclaration::query()
            ->with(['user:id,name,email'])
            ->where('statut', 'en_attente_manager')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (PointageDeclaration $d) => $this->userCanValidateAsManager($user, $d));

        $history = PointageDeclaration::query()
            ->with(['user:id,name,email', 'managerUser:id,name', 'rhUser:id,name'])
            ->whereIn('statut', ['en_attente_rh', 'valide', 'rejete'])
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get();

        return Inertia::render('pointage/ValidationsManager', [
            'pending' => $pending->map(fn (PointageDeclaration $d) => $this->serializeDeclarationRow($d))->values(),
            'history' => $history->map(fn (PointageDeclaration $d) => $this->serializeDeclarationRow($d))->values(),
        ]);
    }

    public function decisionManager(Request $request, PointageDeclaration $declaration)
    {
        $user = Auth::user();
        abort_unless($user && $this->userCanValidateAsManager($user, $declaration), 403);
        abort_unless($declaration->statut === 'en_attente_manager', 422);

        $validated = $request->validate([
            'accept' => 'required|boolean',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validated['accept']) {
            $declaration->update([
                'statut' => 'en_attente_rh',
                'manager_user_id' => $user->id,
                'manager_decided_at' => now(),
                'manager_comment' => $validated['comment'] ?? null,
            ]);
            PointageAuditLog::record($user, 'DECLARATION_VAL_MANAGER_OK', 'Transmis RH', null, $request->ip(), 'ok', ['declaration_id' => $declaration->id]);
        } else {
            $declaration->update([
                'statut' => 'rejete',
                'manager_user_id' => $user->id,
                'manager_decided_at' => now(),
                'manager_comment' => $validated['comment'] ?? null,
            ]);
            PointageAuditLog::record($user, 'DECLARATION_VAL_MANAGER_KO', 'Rejet manager', null, $request->ip(), 'alerte', ['declaration_id' => $declaration->id]);
        }

        return back()->with('success', 'Décision enregistrée.');
    }

    public function validationRh(Request $request)
    {
        return redirect()->route('pointage.declarations.demande', [
            'statut' => 'en_attente_rh',
            'mois' => $request->input('mois', Carbon::now()->format('Y-m')),
        ]);
    }

    public function decisionRh(Request $request, PointageDeclaration $declaration)
    {
        $user = Auth::user();
        abort_unless($user && ($user->isRh() || $user->isAdmin() || $user->isSuperAdmin()), 403);
        abort_unless($declaration->statut === 'en_attente_rh', 422);

        $validated = $request->validate([
            'accept' => 'required|boolean',
            'comment' => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($validated, $declaration, $user, $request): void {
            if ($validated['accept']) {
                $declaration->update([
                    'statut' => 'valide',
                    'rh_user_id' => $user->id,
                    'rh_decided_at' => now(),
                    'rh_comment' => $validated['comment'] ?? null,
                ]);
                $declaration->refresh();
                $applied = app(PointageDeclarationPresenceService::class)->appliquerApresValidationRh($declaration);
                PointageAuditLog::record(
                    $user,
                    'DECLARATION_VAL_RH_OK',
                    'Déclaration validée RH — heures effectives 08:00/17:00 appliquées',
                    null,
                    $request->ip(),
                    'ok',
                    ['declaration_id' => $declaration->id, 'pointages_ajustes' => $applied]
                );
            } else {
                $declaration->update([
                    'statut' => 'rejete',
                    'rh_user_id' => $user->id,
                    'rh_decided_at' => now(),
                    'rh_comment' => $validated['comment'] ?? null,
                ]);
                PointageAuditLog::record($user, 'DECLARATION_VAL_RH_KO', 'Déclaration rejetée RH', null, $request->ip(), 'alerte', ['declaration_id' => $declaration->id]);
            }
        });

        return back()->with('success', 'Décision RH enregistrée.');
    }

    private function userCanValidateAsManager(\App\Models\User $user, PointageDeclaration $declaration): bool
    {
        if ($user->isAdmin() || $user->isSuperAdmin()) {
            return true;
        }

        $declaration->user->profilCollaborateurAssocie();
        $declarerProfil = $declaration->user->profil;

        $user->profilCollaborateurAssocie();
        $me = $user->profil;

        if (! $declarerProfil || ! $me) {
            return false;
        }

        if ($declarerProfil->n_plus_1_id === $me->id) {
            return true;
        }

        if (! $user->isResponsableDepartement()) {
            return false;
        }

        $managed = Departement::query()
            ->where('responsable_departement_id', $me->id)
            ->where('actif', true)
            ->pluck('nom')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)));

        $dept = mb_strtolower(trim((string) ($declarerProfil->getRawOriginal('departement') ?? $declarerProfil->departement)));

        return $managed->contains($dept);
    }

    private function serializeDeclarationRow(PointageDeclaration $d): array
    {
        $path = $d->justificatif_path;
        $fileLabel = $path ? basename((string) $path) : null;
        $dateFin = $d->date_fin?->format('Y-m-d');
        $dateFinDisplay = $d->date_fin?->format('d/m/Y');
        $start = $d->date_concernee?->copy()->startOfDay();
        $end = ($d->date_fin ?? $d->date_concernee)?->copy()->startOfDay();
            $nbJours = ($start && $end) ? (int) max(1, $start->diffInDays($end) + 1) : 1;
        $dateReprise = $end?->copy()->addDay();

        $d->loadMissing('user.profil');

        return [
            'id' => $d->id,
            'type' => $d->type,
            'type_label' => PointageDeclarationTypes::label((string) $d->type),
            'date_concernee' => $d->date_concernee?->format('Y-m-d'),
            'date_concernee_display' => $d->date_concernee?->format('d/m/Y'),
            'date_concernee_short' => $d->date_concernee?->format('d M, y'),
            'date_fin' => $dateFin,
            'date_fin_display' => $dateFinDisplay,
            'date_fin_short' => $d->date_fin?->format('d M, y') ?? $d->date_concernee?->format('d M, y'),
            'nb_jours' => $nbJours,
            'date_reprise_display' => $dateReprise?->format('d/m/Y'),
            'heure_debut' => $d->heure_debut,
            'heure_fin' => $d->heure_fin,
            'lieu' => $d->lieu,
            'motif' => $d->motif,
            'commentaire' => $d->commentaire,
            'has_justificatif' => (bool) $path,
            'justificatif_filename' => $fileLabel,
            'statut' => $d->statut,
            'statut_label' => $this->statutLabel($d->statut),
            'validateur_label' => $this->validateurLabel($d),
            'user' => $d->relationLoaded('user') ? [
                'id' => $d->user?->id,
                'name' => $d->user?->name,
                'email' => $d->user?->email,
                'fonction' => $d->user?->profil?->fonction,
            ] : null,
            'manager_user' => $d->relationLoaded('managerUser') ? [
                'name' => $d->managerUser?->name,
            ] : null,
            'rh_user' => $d->relationLoaded('rhUser') ? [
                'name' => $d->rhUser?->name,
            ] : null,
            'manager_comment' => $d->manager_comment,
            'rh_comment' => $d->rh_comment,
            'manager_decided_at' => $d->manager_decided_at?->format('d/m/Y H:i'),
            'rh_decided_at' => $d->rh_decided_at?->format('d/m/Y H:i'),
            'created_at_display' => $d->created_at?->format('d/m/Y H:i'),
            'processus' => $this->processusEtapes($d),
        ];
    }

    /**
     * @return list<array{key: string, label: string, done: bool, current: bool}>
     */
    private function processusEtapes(PointageDeclaration $d): array
    {
        $statut = (string) $d->statut;
        $hasN1 = $d->manager_user_id !== null || $statut === 'en_attente_manager' || $d->manager_decided_at !== null;

        $soumisDone = true;
        $n1Current = $statut === 'en_attente_manager';
        $n1Done = in_array($statut, ['en_attente_rh', 'valide', 'rejete'], true) && ($hasN1 || $d->manager_decided_at);
        // Si passé directement RH sans N+1
        if ($statut === 'en_attente_rh' && $d->manager_user_id === null && $d->manager_decided_at === null) {
            $n1Done = true;
            $n1Current = false;
        }
        if ($statut === 'rejete' && $d->manager_decided_at && ! $d->rh_decided_at) {
            $n1Done = true;
            $n1Current = false;
        }

        $rhCurrent = $statut === 'en_attente_rh';
        $rhDone = in_array($statut, ['valide', 'rejete'], true) && ($d->rh_decided_at !== null || $statut === 'valide');

        if ($statut === 'rejete' && $d->manager_decided_at && ! $d->rh_decided_at) {
            $rhDone = false;
            $rhCurrent = false;
        }

        return [
            ['key' => 'soumis', 'label' => 'Soumise', 'done' => $soumisDone, 'current' => false],
            ['key' => 'n1', 'label' => 'N+1', 'done' => (bool) $n1Done, 'current' => $n1Current],
            ['key' => 'rh', 'label' => 'RH', 'done' => (bool) $rhDone || $statut === 'valide', 'current' => $rhCurrent],
            ['key' => 'final', 'label' => $statut === 'rejete' ? 'Rejetée' : 'Validée', 'done' => in_array($statut, ['valide', 'rejete'], true), 'current' => false],
        ];
    }

    private function typeLabel(string $type): string
    {
        return PointageDeclarationTypes::label($type);
    }

    private function statutLabel(string $statut): string
    {
        return match ($statut) {
            'en_attente_manager' => 'En attente N+1',
            'en_attente_rh' => 'En attente RH',
            'valide' => 'Validé',
            'rejete' => 'Rejeté',
            default => $statut,
        };
    }

    private function validateurLabel(PointageDeclaration $d): string
    {
        if (in_array($d->statut, ['en_attente_manager', 'en_attente_rh'], true)) {
            return '—';
        }

        if ($d->statut === 'rejete') {
            if ($d->rh_decided_at && $d->rhUser) {
                return $d->rhUser->name.' (RH)';
            }
            if ($d->managerUser) {
                return $d->managerUser->name.' (Manager)';
            }

            return '—';
        }

        if ($d->statut === 'valide' && $d->rhUser) {
            return $d->rhUser->name.' (RH)';
        }

        return '—';
    }

    private function frenchMonthYearLabel(string $ym): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
            return $ym;
        }
        $monthNum = (int) $m[2];
        $months = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        return ($months[$monthNum] ?? $ym).' '.$m[1];
    }

    /**
     * @return array{manager_nom: string|null, validation_hint: string}
     */
    private function declarationFlowMetaForUser(?\App\Models\User $user): array
    {
        $user?->profilCollaborateurAssocie();
        $profil = $user?->profil;
        if (! $profil?->n_plus_1_id) {
            return [
                'manager_nom' => null,
                'validation_hint' => 'Votre déclaration sera soumise directement à la RH pour validation.',
            ];
        }

        $mgr = Profil::query()->find($profil->n_plus_1_id);
        $nom = $mgr ? trim(($mgr->prenom ?? '').' '.($mgr->nom ?? '')) : null;
        if ($nom === '') {
            $nom = null;
        }

        $hint = $nom
            ? "Votre déclaration sera soumise à {$nom} (Manager) pour validation, puis à la RH."
            : 'Votre déclaration sera soumise à votre manager pour validation, puis à la RH.';

        return [
            'manager_nom' => $nom,
            'validation_hint' => $hint,
        ];
    }
}
