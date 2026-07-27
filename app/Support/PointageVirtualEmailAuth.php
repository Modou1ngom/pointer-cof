<?php

namespace App\Support;

use App\Models\Agence;
use App\Models\Profil;
use App\Models\User;

/**
 * Identification de l’employé qui pointe sur une agence virtuelle (borne partagée).
 * L’e-mail n’a pas besoin de correspondre au compte connecté (compte borne / défaut).
 */
final class PointageVirtualEmailAuth
{
    public const REQUIRED_MESSAGE = 'L’e-mail est requis pour valider le pointage sur une agence virtuelle.';

    public const UNKNOWN_MESSAGE = 'Aucun collaborateur actif trouvé pour cet e-mail.';

    public const NOT_ENROLLED_MESSAGE = 'Ce collaborateur n’est pas enrôlé sur cette agence virtuelle.';

    /**
     * @return array{ok: bool, message?: string, email?: string, user?: User}
     */
    public static function resolveEnrolledPunchUser(Agence $agence, ?string $email): array
    {
        $input = self::normalize($email);
        if ($input === '') {
            return ['ok' => false, 'message' => self::REQUIRED_MESSAGE];
        }

        if (! filter_var($input, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Adresse e-mail invalide.'];
        }

        $user = self::findUserByEmail($input);
        if ($user === null) {
            return ['ok' => false, 'message' => self::UNKNOWN_MESSAGE];
        }

        if (! $user->is_active) {
            return ['ok' => false, 'message' => 'Compte collaborateur désactivé.'];
        }

        $enrolment = PointageEnrolment::ensureAuthorized($user, $agence);
        if (! $enrolment['ok']) {
            return [
                'ok' => false,
                'message' => $enrolment['message'] ?? self::NOT_ENROLLED_MESSAGE,
            ];
        }

        return ['ok' => true, 'email' => $input, 'user' => $user];
    }

    public static function findUserByEmail(string $email): ?User
    {
        $email = self::normalize($email);
        if ($email === '') {
            return null;
        }

        $user = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if ($user !== null) {
            return $user;
        }

        $profil = Profil::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        if ($profil === null) {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [self::normalize($profil->email)])
            ->first();
    }

    public static function normalize(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }
}
