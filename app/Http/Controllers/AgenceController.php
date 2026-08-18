<?php

namespace App\Http\Controllers;

use App\Models\Agence;
use App\Models\Filiale;
use App\Models\Profil;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AgenceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $perPage = (int) $request->get('per_page', 5);

        $query = Agence::query()->with('filiale:id,nom');

        $ids = $this->allowedFilialeIdsForUser($user);
        if ($ids === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('filiale_id', $ids);
        }

        $agences = $query->orderBy('nom')->paginate($perPage);

        // Compter le nombre de profils par agence
        $agences->each(function ($agence) {
            $agence->profils_count = Profil::where('site', $agence->nom)->count();
        });

        return Inertia::render('agences/Index', [
            'agences' => $agences,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $profils = Profil::orderBy('nom')->get(['id', 'nom', 'prenom', 'matricule']);

        return Inertia::render('agences/Create', [
            'profils' => $profils,
            'filiales' => $this->filialesForUser(Auth::user()),
            'defaultFilialeId' => $this->defaultFilialeIdForUser(Auth::user()),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->merge($this->normalizedGpsInput($request));
        $this->applyDefaultFilialeForUser($request, Auth::user());

        $validated = $this->validateAgencePayload($request);

        Agence::create([
            'nom' => $validated['nom'],
            'code_agent' => $validated['code_agent'],
            'description' => $validated['description'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'actif' => $validated['actif'] === 'actif',
            'chef_agence_id' => $validated['chef_agence_id'] ?? null,
            'filiale_id' => $validated['filiale_id'] ?? null,
        ]);

        return redirect()->route('agences.index')
            ->with('success', 'Agence créée avec succès !');
    }

    /**
     * Display the specified resource.
     */
    public function show(Agence $agence)
    {
        $this->authorizeAgenceVisible($agence);

        $agence->load('chefAgence');
        $profils = Profil::where('site', $agence->nom)->get();

        return Inertia::render('agences/Show', [
            'agence' => $agence,
            'profils' => $profils,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Agence $agence)
    {
        $this->authorizeAgenceVisible($agence);

        $profils = Profil::orderBy('nom')->get(['id', 'nom', 'prenom', 'matricule']);

        return Inertia::render('agences/Edit', [
            'agence' => $agence->load('filiale:id,nom'),
            'profils' => $profils,
            'filiales' => $this->filialesForUser(Auth::user()),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Agence $agence)
    {
        $this->authorizeAgenceVisible($agence);

        $request->merge($this->normalizedGpsInput($request));
        $this->applyDefaultFilialeForUser($request, Auth::user());

        $validated = $this->validateAgencePayload($request, $agence);

        $agence->update([
            'nom' => $validated['nom'],
            'code_agent' => $validated['code_agent'],
            'description' => $validated['description'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'actif' => $validated['actif'] === 'actif',
            'chef_agence_id' => $validated['chef_agence_id'] ?? null,
            'filiale_id' => $validated['filiale_id'] ?? null,
        ]);

        return redirect()->route('agences.index')
            ->with('success', 'Agence mise à jour avec succès !');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Agence $agence)
    {
        $this->authorizeAgenceVisible($agence);

        $agence->delete();

        return redirect()->route('agences.index')
            ->with('success', 'Agence supprimée avec succès !');
    }

    /**
     * @return array{latitude: float|null, longitude: float|null}
     */
    /**
     * @return list<int>
     */
    private function allowedFilialeIdsForUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return $user->filialeIdsDuPerimetre();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Filiale>
     */
    private function filialesForUser(?User $user)
    {
        $query = Filiale::query()->where('actif', true)->orderBy('nom');

        $ids = $this->allowedFilialeIdsForUser($user);
        if ($ids === []) {
            return collect();
        }
        $query->whereIn('id', $ids);

        return $query->get(['id', 'nom']);
    }

    private function defaultFilialeIdForUser(?User $user): ?int
    {
        $ids = $this->allowedFilialeIdsForUser($user);
        if (count($ids) !== 1) {
            return null;
        }

        return $ids[0];
    }

    private function applyDefaultFilialeForUser(Request $request, ?User $user): void
    {
        if ($request->filled('filiale_id')) {
            return;
        }

        $default = $this->defaultFilialeIdForUser($user);
        if ($default !== null) {
            $request->merge(['filiale_id' => $default]);
        }
    }

    private function authorizeAgenceVisible(Agence $agence): void
    {
        $user = Auth::user();
        $allowed = $this->allowedFilialeIdsForUser($user);
        if ($agence->filiale_id === null || in_array((int) $agence->filiale_id, $allowed, true)) {
            return;
        }

        abort(403, 'Vous n’avez pas accès à cette agence.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAgencePayload(Request $request, ?Agence $agence = null): array
    {
        $user = Auth::user();
        $allowedFilialeIds = $this->allowedFilialeIdsForUser($user);

        $filialeRules = ['nullable', 'exists:filiales,id', Rule::in($allowedFilialeIds)];

        $uniqueAgenceRule = function (string $column) use ($user, $allowedFilialeIds, $agence) {
            return function (string $attribute, mixed $value, \Closure $fail) use ($column, $user, $allowedFilialeIds, $agence) {
                if (! is_string($value) || $value === '') {
                    return;
                }

                $query = Agence::query()->where($column, $value);
                if ($agence) {
                    $query->whereKeyNot($agence->id);
                }

                $existing = $query->first();
                if (! $existing) {
                    return;
                }

                if ($user && ! in_array((int) $existing->filiale_id, $allowedFilialeIds, true)) {
                    $filialeLabel = $existing->filiale?->nom
                        ?? Filiale::query()->whereKey($existing->filiale_id)->value('nom')
                        ?? 'autre filiale';

                    $fail(sprintf(
                        'Cette valeur appartient déjà à l’agence « %s » (filiale %s), qui n’apparaît pas dans votre liste. Utilisez un autre %s ou demandez à un super administrateur de la rattacher à votre filiale.',
                        $existing->nom,
                        $filialeLabel,
                        $column === 'nom' ? 'nom' : 'code'
                    ));

                    return;
                }

                $fail($column === 'nom'
                    ? 'Ce nom d’agence est déjà utilisé.'
                    : 'Ce code agent est déjà utilisé.');
            };
        };

        return \Illuminate\Support\Facades\Validator::make(
            $request->all(),
            [
                'nom' => ['required', 'string', 'max:255', $uniqueAgenceRule('nom')],
                'code_agent' => ['required', 'string', 'max:50', $uniqueAgenceRule('code_agent')],
                'description' => 'nullable|string',
                'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
                'actif' => 'required|in:actif,inactif',
                'chef_agence_id' => 'nullable|exists:profiles,id',
                'filiale_id' => $filialeRules,
            ],
            [
                'filiale_id.in' => 'Vous ne pouvez affecter une agence qu’à une filiale de votre périmètre.',
            ],
            [
                'nom' => 'nom de l’agence',
                'code_agent' => 'code agent',
                'filiale_id' => 'filiale',
            ]
        )->validate();
    }

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
