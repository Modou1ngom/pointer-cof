<?php

namespace App\Http\Controllers;

use App\Models\Filiale;
use App\Models\Profil;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FilialeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = auth()->user();
        $query = Filiale::query()->orderBy('nom');
        if ($user) {
            $ids = $user->filialeIdsDuPerimetre();
            if ($ids !== []) {
                $query->whereIn('id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
        $filiales = $query->get();
        
        // Compter le nombre de profils par filiale
        $filiales->each(function ($filiale) {
            $filiale->profils_count = Profil::where('site', $filiale->nom)->count();
        });
        
        return Inertia::render('filiales/Index', [
            'filiales' => $filiales,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('filiales/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255|unique:filiales,nom',
            'description' => 'nullable|string',
            'actif' => 'required|in:actif,inactif',
        ]);

        Filiale::create([
            'nom' => $validated['nom'],
            'description' => $validated['description'] ?? null,
            'actif' => $validated['actif'] === 'actif',
        ]);

        return redirect()->route('filiales.index')
            ->with('success', 'Filiale créée avec succès !');
    }

    /**
     * Display the specified resource.
     */
    public function show(Filiale $filiale)
    {
        $this->authorizeFiliale($filiale);
        $profils = Profil::where('filiale_id', $filiale->id)->get();
        
        return Inertia::render('filiales/Show', [
            'filiale' => $filiale,
            'profils' => $profils,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Filiale $filiale)
    {
        $this->authorizeFiliale($filiale);
        return Inertia::render('filiales/Edit', [
            'filiale' => $filiale,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Filiale $filiale)
    {
        $this->authorizeFiliale($filiale);
        $validated = $request->validate([
            'nom' => 'required|string|max:255|unique:filiales,nom,' . $filiale->id,
            'description' => 'nullable|string',
            'actif' => 'required|in:actif,inactif',
        ]);

        $filiale->update([
            'nom' => $validated['nom'],
            'description' => $validated['description'] ?? null,
            'actif' => $validated['actif'] === 'actif',
        ]);

        return redirect()->route('filiales.index')
            ->with('success', 'Filiale mise à jour avec succès !');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Filiale $filiale)
    {
        $this->authorizeFiliale($filiale);
        $filiale->delete();
        
        return redirect()->route('filiales.index')
            ->with('success', 'Filiale supprimée avec succès !');
    }

    private function authorizeFiliale(Filiale $filiale): void
    {
        $user = auth()->user();
        if (! $user || ! $user->appartientAuPerimetreFiliale((int) $filiale->id)) {
            abort(403, 'Vous n’avez pas accès à cette filiale.');
        }
    }
}
