<?php

namespace App\Support;

use App\Models\Agence;

final class PointageGeofencing
{
    public const ERROR_TITLE = 'Erreur GPS / géorepérage';

    /**
     * @return array{
     *     ok: bool,
     *     error?: string,
     *     message?: string,
     *     title?: string,
     *     hint?: string,
     *     distance_metres?: float,
     *     rayon_autorise_metres?: float,
     *     site_latitude?: float,
     *     site_longitude?: float,
     *     scan_latitude?: float,
     *     scan_longitude?: float,
     *     agence_nom?: string,
     * }
     */
    public static function validate(Agence $agence, float $latitude, float $longitude): array
    {
        if (! (bool) config('pointage.geofencing_enabled', true)) {
            return ['ok' => true];
        }

        // Agence virtuelle (borne partagée) : pas de contrainte GPS sur le téléphone employé.
        if ($agence->isVirtual()) {
            return [
                'ok' => true,
                'distance_metres' => null,
                'rayon_autorise_metres' => null,
                'site_latitude' => $agence->latitude !== null ? (float) $agence->latitude : null,
                'site_longitude' => $agence->longitude !== null ? (float) $agence->longitude : null,
                'scan_latitude' => $latitude,
                'scan_longitude' => $longitude,
                'agence_nom' => $agence->nom,
                'virtual_skip' => true,
            ];
        }

        if ($agence->latitude === null || $agence->longitude === null) {
            return [
                'ok' => false,
                'error' => 'geofencing_not_configured',
                'title' => self::ERROR_TITLE,
                'message' => sprintf(
                    '%s : le site « %s » n’a pas de position GPS enregistrée. Le pointage est bloqué jusqu’à configuration du bureau.',
                    self::ERROR_TITLE,
                    $agence->nom
                ),
                'hint' => 'Contactez le service RH ou IT pour géolocaliser ce site dans l’application web Pointage.',
                'agence_nom' => $agence->nom,
            ];
        }

        $siteLat = (float) $agence->latitude;
        $siteLng = (float) $agence->longitude;
        $distance = PointageGeo::distanceMeters($siteLat, $siteLng, $latitude, $longitude);
        $maxRadius = (float) ($agence->rayon_geofencing_metres ?? config('pointage.default_geofencing_radius_metres', 50));

        $base = [
            'distance_metres' => round($distance, 1),
            'rayon_autorise_metres' => $maxRadius,
            'site_latitude' => $siteLat,
            'site_longitude' => $siteLng,
            'scan_latitude' => $latitude,
            'scan_longitude' => $longitude,
            'agence_nom' => $agence->nom,
        ];

        if ($distance > $maxRadius) {
            return array_merge($base, [
                'ok' => false,
                'error' => 'geofencing',
                'title' => self::ERROR_TITLE,
                'message' => sprintf(
                    '%s : vous êtes à %.0f m du site « %s ». Le pointage n’est autorisé qu’à moins de %.0f m du bureau.',
                    self::ERROR_TITLE,
                    $distance,
                    $agence->nom,
                    $maxRadius
                ),
                'hint' => 'Activez la localisation sur l’appareil, rapprochez-vous du site de pointage, puis réessayez. En test sur émulateur, définissez la position GPS sur les coordonnées du site.',
            ]);
        }

        return array_merge($base, ['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function toJsonError(array $result): array
    {
        $code = (string) ($result['error'] ?? 'geofencing');
        $message = (string) ($result['message'] ?? self::fallbackMessage($code));
        $title = (string) ($result['title'] ?? self::ERROR_TITLE);
        $hint = (string) ($result['hint'] ?? self::hintForCode($code));

        $geofencing = [
            'distance_metres' => $result['distance_metres'] ?? null,
            'rayon_autorise_metres' => $result['rayon_autorise_metres'] ?? null,
            'site_latitude' => $result['site_latitude'] ?? null,
            'site_longitude' => $result['site_longitude'] ?? null,
            'scan_latitude' => $result['scan_latitude'] ?? null,
            'scan_longitude' => $result['scan_longitude'] ?? null,
            'agence_nom' => $result['agence_nom'] ?? null,
            'title' => $title,
            'message' => $message,
            'hint' => $hint,
        ];

        return [
            'title' => $title,
            'message' => $message,
            'user_message' => $message,
            'userMessage' => $message,
            'hint' => $hint,
            'error' => $code,
            'error_code' => $code,
            'error_label' => self::ERROR_TITLE,
            'geofencing' => $geofencing,
        ];
    }

    private static function fallbackMessage(string $code): string
    {
        return match ($code) {
            'geofencing_not_configured' => self::ERROR_TITLE.' : le site de pointage n’est pas géolocalisé.',
            default => self::ERROR_TITLE.' : votre position est hors de la zone autorisée pour pointer.',
        };
    }

    private static function hintForCode(string $code): string
    {
        return match ($code) {
            'geofencing_not_configured' => 'Demandez au RH de renseigner latitude, longitude et rayon du site dans Pointage & Présence.',
            default => 'Vérifiez le GPS, autorisez la localisation pour CofiPointe, et placez-vous sur le site de travail.',
        };
    }
}
