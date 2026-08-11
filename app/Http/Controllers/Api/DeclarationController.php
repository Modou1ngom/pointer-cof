<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointageAuditLog;
use App\Models\PointageDeclaration;
use App\Support\PointageDeclarationTypes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeclarationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $mois = $request->query('mois', Carbon::now()->format('Y-m'));
        if (! is_string($mois) || ! preg_match('/^\d{4}-\d{2}$/', $mois)) {
            $mois = Carbon::now()->format('Y-m');
        }
        [$year, $month] = array_map('intval', explode('-', $mois, 2));

        $rows = PointageDeclaration::query()
            ->where('user_id', $user->id)
            ->where(function ($q) use ($year, $month): void {
                $q->where(function ($w) use ($year, $month): void {
                    $w->whereYear('date_concernee', $year)->whereMonth('date_concernee', $month);
                })->orWhere(function ($w) use ($year, $month): void {
                    $w->whereNotNull('date_fin')
                        ->whereYear('date_fin', $year)
                        ->whereMonth('date_fin', $month);
                });
            })
            ->orderByDesc('date_concernee')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (PointageDeclaration $d) => $this->serialize($d));

        return response()->json([
            'data' => $rows,
            'mois' => $mois,
            'types' => PointageDeclarationTypes::optionsForApi(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
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

        $declaration = PointageDeclaration::query()->create([
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

        PointageAuditLog::record(
            $user,
            'DECLARATION_SOUMISE',
            'Déclaration mobile : '.PointageDeclarationTypes::label($declaration->type),
            null,
            $request->ip(),
            'ok',
            ['declaration_id' => $declaration->id]
        );

        return response()->json([
            'message' => $statut === 'en_attente_manager'
                ? 'Déclaration envoyée à votre N+1 pour validation.'
                : 'Déclaration envoyée au RH pour validation.',
            'data' => $this->serialize($declaration),
        ], 201);
    }

    public function show(Request $request, PointageDeclaration $declaration): JsonResponse
    {
        abort_unless((int) $declaration->user_id === (int) $request->user()->id, 403);

        return response()->json(['data' => $this->serialize($declaration)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PointageDeclaration $d): array
    {
        return [
            'id' => $d->id,
            'type' => $d->type,
            'type_label' => PointageDeclarationTypes::label((string) $d->type),
            'date_concernee' => $d->date_concernee?->toDateString(),
            'date_fin' => $d->date_fin?->toDateString(),
            'heure_debut' => $d->heure_debut,
            'heure_fin' => $d->heure_fin,
            'lieu' => $d->lieu,
            'motif' => $d->motif,
            'commentaire' => $d->commentaire,
            'has_justificatif' => $d->hasJustificatif(),
            'statut' => $d->statut,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
