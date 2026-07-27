<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointageDeclaration;
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
            ->whereYear('date_concernee', $year)
            ->whereMonth('date_concernee', $month)
            ->orderByDesc('date_concernee')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (PointageDeclaration $d) => $this->serialize($d));

        return response()->json([
            'data' => $rows,
            'mois' => $mois,
            'types' => [
                ['value' => 'retard', 'label' => 'Retard'],
                ['value' => 'absence', 'label' => 'Absence'],
                ['value' => 'conge', 'label' => 'Congé'],
                ['value' => 'regularisation', 'label' => 'Régularisation'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'type' => 'required|in:retard,absence,conge,regularisation',
            'date_concernee' => 'required|date',
            'motif' => 'required|string|max:512',
            'commentaire' => 'nullable|string|max:2000',
        ]);

        $user->profilCollaborateurAssocie();
        $profil = $user->profil;
        $statut = ($profil && $profil->n_plus_1_id) ? 'en_attente_manager' : 'en_attente_rh';

        $declaration = PointageDeclaration::query()->create([
            'user_id' => $user->id,
            'type' => $validated['type'],
            'date_concernee' => $validated['date_concernee'],
            'motif' => $validated['motif'],
            'commentaire' => $validated['commentaire'] ?? null,
            'statut' => $statut,
        ]);

        return response()->json([
            'message' => 'Déclaration enregistrée.',
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
            'date_concernee' => $d->date_concernee?->toDateString(),
            'motif' => $d->motif,
            'commentaire' => $d->commentaire,
            'statut' => $d->statut,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
