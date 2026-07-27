<?php

namespace App\Http\Controllers;

use App\Models\Agence;
use App\Services\PointageQrService;
use App\Support\PointageJourSemaine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Affichage borne / tablette : QR site scannable par les employés (app mobile).
 * Accès via jeton secret d’agence (pas de session RH sur la tablette).
 */
class PointageKioskController extends Controller
{
    public function show(string $token, PointageQrService $qr): Response
    {
        $resolved = $this->resolveAgence($token);

        if ($resolved['error'] !== null) {
            return Inertia::render('Pointage/Kiosk', [
                'agence' => $resolved['agence'] ? [
                    'id' => $resolved['agence']->id,
                    'nom' => $resolved['agence']->nom,
                    'code_agent' => $resolved['agence']->code_agent,
                    'pointage_qr_type' => $resolved['agence']->pointage_qr_type ?? 'dynamic',
                    'rayon_geofencing_metres' => $resolved['agence']->rayon_geofencing_metres ?? 50,
                ] : null,
                'qr' => null,
                'refresh_url' => null,
                'location_url' => null,
                'auto_site_gps' => false,
                'kiosk_unavailable' => true,
                'unavailable_message' => $resolved['error'],
                'qr_inactif_jour' => false,
                'qr_inactif_message' => null,
            ]);
        }

        $agence = $resolved['agence'];
        $minted = $qr->mintToken($agence);
        $autoSiteGps = (bool) config('pointage.kiosk_auto_site_gps', true);

        return Inertia::render('Pointage/Kiosk', [
            'agence' => [
                'id' => $agence->id,
                'nom' => $agence->nom,
                'code_agent' => $agence->code_agent,
                'pointage_qr_type' => $agence->isVirtual() ? 'static' : ($agence->pointage_qr_type ?? 'dynamic'),
                'is_virtual' => $agence->isVirtual(),
                'rayon_geofencing_metres' => $agence->rayon_geofencing_metres ?? 50,
                'has_site_gps' => $agence->latitude !== null && $agence->longitude !== null,
            ],
            'qr' => $minted,
            // Virtuel / static : pas de refresh auto (QR longue durée).
            'refresh_url' => ($agence->isVirtual() || ($agence->pointage_qr_type === 'static'))
                ? null
                : route('pointage.kiosk.qr', ['token' => $agence->pointage_kiosk_token]),
            'location_url' => $autoSiteGps
                ? route('pointage.kiosk.location', ['token' => $agence->pointage_kiosk_token])
                : null,
            'auto_site_gps' => $autoSiteGps,
            'kiosk_unavailable' => false,
            'unavailable_message' => null,
            'qr_inactif_jour' => PointageJourSemaine::isJourQrInactif(),
            'qr_inactif_message' => PointageJourSemaine::isJourQrInactif()
                ? PointageJourSemaine::messageQrInactif()
                : null,
        ]);
    }

    /**
     * Enregistre la position GPS de la borne / tablette comme coordonnées du site
     * (référence pour le géorepérage au scan employé).
     */
    public function syncLocation(Request $request, string $token): JsonResponse
    {
        if (! (bool) config('pointage.kiosk_auto_site_gps', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'disabled',
                'message' => 'Synchronisation GPS borne désactivée.',
            ], 403);
        }

        $resolved = $this->resolveAgence($token);

        if ($resolved['error'] !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'kiosk_unavailable',
                'message' => $resolved['error'],
            ], 403);
        }

        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_metres' => 'nullable|numeric|min:0|max:5000',
        ]);

        $maxAccuracy = (float) config('pointage.kiosk_auto_site_gps_max_accuracy_metres', 100);
        $agence = $resolved['agence'];
        $hasExistingGps = $agence->latitude !== null && $agence->longitude !== null;

        // Ne jamais écraser une position déjà configurée (PC/Wi‑Fi souvent imprécis).
        if ($hasExistingGps) {
            return response()->json([
                'ok' => true,
                'skipped' => true,
                'message' => 'Position du site déjà enregistrée — non modifiée.',
                'agence' => [
                    'id' => $agence->id,
                    'nom' => $agence->nom,
                    'latitude' => (float) $agence->latitude,
                    'longitude' => (float) $agence->longitude,
                ],
            ]);
        }

        if (
            isset($validated['accuracy_metres'])
            && (float) $validated['accuracy_metres'] > $maxAccuracy
        ) {
            return response()->json([
                'ok' => false,
                'error' => 'accuracy_too_low',
                'message' => sprintf(
                    'Précision GPS insuffisante (%.0f m). Configurez le GPS du site depuis un téléphone sur place (Sites → Éditer), ou placez la tablette près d’une fenêtre.',
                    (float) $validated['accuracy_metres']
                ),
            ], 422);
        }

        $agence->forceFill([
            'latitude' => round((float) $validated['latitude'], 7),
            'longitude' => round((float) $validated['longitude'], 7),
        ])->save();

        return response()->json([
            'ok' => true,
            'message' => 'Position du site mise à jour depuis la borne.',
            'agence' => [
                'id' => $agence->id,
                'nom' => $agence->nom,
                'latitude' => (float) $agence->latitude,
                'longitude' => (float) $agence->longitude,
            ],
        ]);
    }

    public function refresh(string $token, PointageQrService $qr): JsonResponse
    {
        $resolved = $this->resolveAgence($token);

        if ($resolved['error'] !== null) {
            return response()->json([
                'ok' => false,
                'error' => 'kiosk_unavailable',
                'message' => $resolved['error'],
            ], 403);
        }

        $agence = $resolved['agence'];

        if (PointageJourSemaine::isJourQrInactif()) {
            return response()->json([
                'ok' => false,
                'error' => 'qr_inactif_jour',
                'message' => PointageJourSemaine::messageQrInactif(),
            ], 422);
        }

        $minted = $qr->mintToken($agence);

        return response()->json([
            'ok' => true,
            'qr' => $minted,
        ]);
    }

    /**
     * @return array{agence: Agence|null, error: string|null}
     */
    private function resolveAgence(string $token): array
    {
        $token = trim($token);
        abort_if($token === '' || strlen($token) < 16, 404);

        $agence = Agence::query()
            ->where('pointage_kiosk_token', $token)
            ->first();

        abort_if($agence === null, 404);
        abort_unless($agence->isEnrolledForPointageQr(), 404);

        if (! $agence->actif) {
            return [
                'agence' => $agence,
                'error' => 'Cette agence est inactive. Réactivez-la dans Pointage → Sites.',
            ];
        }

        if (! ($agence->pointage_qr_enabled ?? true)) {
            return [
                'agence' => $agence,
                'error' => 'Le QR de cette agence est en pause. Dans Pointage → Sites, cliquez sur « Activer QR ».',
            ];
        }

        return [
            'agence' => $agence,
            'error' => null,
        ];
    }
}
