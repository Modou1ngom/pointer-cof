<?php

namespace App\Support;

use App\Models\Agence;
use App\Models\PointageAffectation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Vérifie qu'un utilisateur est autorisé (enrôlé) à pointer sur une agence donnée.
 *
 * Source principale : affectation pointage RH (pointage_affectation_agences).
 * Repli : pivot agence_user (legacy / synchronisation).
 */
final class PointageEnrolment
{
    /**
     * @return array{ok: bool, reason?: string, message?: string}
     */
    public static function ensureAuthorized(User $user, Agence $agence, ?Carbon $at = null): array
    {
        $at = $at ?? Carbon::now();

        if (! $user->is_active) {
            return [
                'ok' => false,
                'reason' => 'user_inactive',
                'message' => 'Compte collaborateur désactivé. Pointage non autorisé.',
            ];
        }

        $user->profilCollaborateurAssocie();
        $profil = $user->profil;
        if ($profil !== null && strtolower((string) $profil->statut) !== 'actif') {
            return [
                'ok' => false,
                'reason' => 'profil_inactive',
                'message' => 'Profil RH inactif. Pointage non autorisé.',
            ];
        }

        $affectation = self::resolveAffectation($user);
        if ($affectation !== null) {
            return self::ensureAuthorizedViaAffectation($affectation, $agence, $at);
        }

        return self::ensureAuthorizedViaAgenceUser($user, $agence, $at);
    }

    public static function isAuthorized(User $user, Agence $agence, ?Carbon $at = null): bool
    {
        return self::ensureAuthorized($user, $agence, $at)['ok'] === true;
    }

    private static function resolveAffectation(User $user): ?PointageAffectation
    {
        $byUser = PointageAffectation::query()
            ->where('user_id', $user->id)
            ->first();

        if ($byUser !== null) {
            return $byUser;
        }

        $email = mb_strtolower(trim((string) $user->email));
        if ($email === '') {
            return null;
        }

        return PointageAffectation::query()
            ->whereHas('profil', function ($q) use ($email) {
                $q->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
            })
            ->first();
    }

    /**
     * @return array{ok: bool, reason?: string, message?: string}
     */
    private static function ensureAuthorizedViaAffectation(PointageAffectation $affectation, Agence $agence, Carbon $at): array
    {
        $affectation->syncUserLinkFromProfilEmail();
        $affectation->syncAgencesToUserPivot();

        if (! $affectation->statut_activation) {
            return [
                'ok' => false,
                'reason' => 'enrolment_inactive',
                'message' => sprintf(
                    'Votre enrôlement au pointage est désactivé. Contactez le RH pour le site « %s ».',
                    $agence->nom
                ),
            ];
        }

        $affectationStart = self::parseDateStart($affectation->date_affectation);
        if ($affectationStart !== null && $at->lt($affectationStart)) {
            return [
                'ok' => false,
                'reason' => 'enrolment_not_started',
                'message' => sprintf(
                    'Votre enrôlement au pointage commence le %s.',
                    $affectationStart->format('d/m/Y')
                ),
            ];
        }

        $affectationEnd = self::parseDateEnd($affectation->date_fin_affectation);
        if ($affectationEnd !== null && $at->gt($affectationEnd)) {
            return [
                'ok' => false,
                'reason' => 'enrolment_expired',
                'message' => sprintf(
                    'Votre enrôlement au pointage a expiré le %s.',
                    $affectationEnd->format('d/m/Y')
                ),
            ];
        }

        $affectation->loadMissing('agences');
        $match = $affectation->agences->firstWhere('id', $agence->id);

        if ($match === null) {
            return [
                'ok' => false,
                'reason' => 'not_enrolled',
                'message' => sprintf(
                    'Vous n\'êtes pas enrôlé sur le site « %s ». Pointage refusé. Contactez le RH pour être rattaché à cette agence.',
                    $agence->nom
                ),
            ];
        }

        return self::validatePivot($agence, $match->pivot, $at);
    }

    /**
     * @return array{ok: bool, reason?: string, message?: string}
     */
    private static function ensureAuthorizedViaAgenceUser(User $user, Agence $agence, Carbon $at): array
    {
        $user->loadMissing('agences');
        $match = $user->agences->firstWhere('id', $agence->id);

        if ($match === null) {
            return [
                'ok' => false,
                'reason' => 'not_enrolled',
                'message' => sprintf(
                    'Vous n\'êtes pas enrôlé sur le site « %s ». Pointage refusé. Contactez le RH pour être rattaché à cette agence.',
                    $agence->nom
                ),
            ];
        }

        return self::validatePivot($agence, $match->pivot, $at);
    }

    /**
     * @param  Pivot|object|null  $pivot
     * @return array{ok: bool, reason?: string, message?: string}
     */
    private static function validatePivot(Agence $agence, mixed $pivot, Carbon $at): array
    {
        $statut = $pivot?->statut_agence ?? 'actif';
        if (is_string($statut) && strtolower($statut) !== 'actif') {
            return [
                'ok' => false,
                'reason' => 'enrolment_inactive',
                'message' => sprintf(
                    'Votre rattachement au site « %s » est suspendu (statut : %s). Contactez le RH.',
                    $agence->nom,
                    $statut
                ),
            ];
        }

        $debut = self::parseDateStart($pivot?->date_debut_autorisation ?? null);
        if ($debut !== null && $at->lt($debut)) {
            return [
                'ok' => false,
                'reason' => 'enrolment_not_started',
                'message' => sprintf(
                    'Votre rattachement au site « %s » commence le %s.',
                    $agence->nom,
                    $debut->format('d/m/Y')
                ),
            ];
        }

        $fin = self::parseDateEnd($pivot?->date_fin_autorisation ?? null);
        if ($fin !== null && $at->gt($fin)) {
            return [
                'ok' => false,
                'reason' => 'enrolment_expired',
                'message' => sprintf(
                    'Votre rattachement au site « %s » a expiré le %s.',
                    $agence->nom,
                    $fin->format('d/m/Y')
                ),
            ];
        }

        return ['ok' => true];
    }

    private static function parseDateStart(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function parseDateEnd(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
