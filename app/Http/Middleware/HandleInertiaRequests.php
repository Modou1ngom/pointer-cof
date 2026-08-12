<?php

namespace App\Http\Middleware;

use App\Models\PointageDeclaration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $profilPayload = null;
        $roles = [];

        if ($user) {
            $user->loadMissing('roles');
            $user->profilCollaborateurAssocie();
            if ($user->profil) {
                $user->profil->loadMissing('roles');
                $p = $user->profil;
                $profilPayload = [
                    'id' => $p->id,
                    'nom' => $p->nom,
                    'prenom' => $p->prenom,
                    'email' => $p->email,
                    'fonction' => $p->fonction,
                    'departement' => $p->departement,
                    'site' => $p->site,
                ];
            }
            $roles = array_values(array_unique(array_merge(
                $user->roles->pluck('slug')->all(),
                $user->profil?->roles->pluck('slug')->all() ?? [],
            )));
        }

        return [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'otp_session_token' => $request->session()->get('otp_session_token'),
            ],
            'name' => config('app.name'),
            'quote' => ['message' => '', 'author' => ''],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar_url,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                ] : null,
                'profil' => $profilPayload,
                'roles' => $roles,
                'isAdmin' => $user ? $user->isAdmin() : false,
                'isSuperAdmin' => $user ? $user->isSuperAdmin() : false,
                'isMetier' => $user ? $user->isMetier() : false,
                'isControle' => $user ? $user->isControle() : false,
                'isRh' => $user ? $user->isRh() : false,
                'isFinance' => $user ? $user->isFinance() : false,
                'isMd' => $user ? $user->isMd() : false,
                'isConformite' => $user ? $user->isConformite() : false,
                'isExecuteurIt' => $user ? $user->isExecuteurIt() : false,
                'isResponsableDepartement' => $user ? $user->isResponsableDepartement() : false,
                'pointageRhDeclarationsPendingCount' => $user && $user->isRh()
                    ? (int) Cache::remember('pointage.rh.pending_declarations', 20, fn () => PointageDeclaration::query()->where('statut', 'en_attente_rh')->count())
                    : 0,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
