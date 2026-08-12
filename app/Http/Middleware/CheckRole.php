<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        // Les super admins ont accès à toutes les routes
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Les admins normaux ont accès à toutes les routes dans leur environnement
        // (ils doivent avoir au moins une filiale assignée)
        if ($user->isAdmin() && ! $user->isSuperAdmin()) {
            $user->loadMissing('filiales');
            if ($user->filiales->isNotEmpty()) {
                return $next($request);
            }
        }

        // Vérifier si l'utilisateur a au moins un des rôles requis
        $hasRole = $user->hasAnyRole($roles);
        
        // Pour les rôles executeur_it ou it, vérifier aussi le profil IT
        if (!$hasRole && (in_array('executeur_it', $roles) || in_array('it', $roles))) {
            $hasRole = $user->isExecuteurIt();
        }

        if (!$hasRole) {
            abort(403, 'Accès non autorisé. Vous n\'avez pas les permissions nécessaires.');
        }

        return $next($request);
    }
}

