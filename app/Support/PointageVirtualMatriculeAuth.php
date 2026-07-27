<?php

namespace App\Support;

use App\Models\User;

/**
 * Validation du matricule pour le pointage sur agence virtuelle (à la place de l’empreinte).
 */
final class PointageVirtualMatriculeAuth
{
    public const MISMATCH_MESSAGE = 'Le matricule saisi ne correspond pas au compte connecté.';

    public const REQUIRED_MESSAGE = 'Le matricule est requis pour valider le pointage sur une agence virtuelle.';

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function assertMatchesUser(User $user, ?string $matricule): array
    {
        $input = self::normalize($matricule);
        if ($input === '') {
            return ['ok' => false, 'message' => self::REQUIRED_MESSAGE];
        }

        $candidates = array_filter([
            self::normalize($user->matricule),
            self::normalize($user->profil?->matricule),
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && strcasecmp($candidate, $input) === 0) {
                return ['ok' => true];
            }
        }

        return ['ok' => false, 'message' => self::MISMATCH_MESSAGE];
    }

    public static function normalize(?string $matricule): string
    {
        return mb_strtoupper(trim((string) $matricule));
    }
}
