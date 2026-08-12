<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ChangePasswordController extends Controller
{
    /**
     * Affiche la page de changement de mot de passe obligatoire.
     */
    public function show(): Response
    {
        return Inertia::render('auth/ChangePassword');
    }

    /**
     * Met à jour le mot de passe de l'utilisateur.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $user = Auth::user();
        
        $user->update([
            'password' => $validated['password'],
            'must_change_password' => false,
        ]);
        $user->tokens()->delete();
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->with('status', 'Votre mot de passe a été modifié avec succès.');
    }
}
