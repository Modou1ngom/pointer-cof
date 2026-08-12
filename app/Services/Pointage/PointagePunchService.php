<?php

namespace App\Services\Pointage;

use App\Models\Agence;
use App\Models\Attendance;
use App\Models\Pointage;
use App\Models\User;
use App\Support\PointageDeviceDayGuard;
use App\Support\PointageEnrolment;
use App\Support\PointageJourSemaine;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class PointagePunchService
{
    public function __construct(
        private readonly PointageHorairesAjustementService $horaires,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message?: string,
     *     pointage?: Pointage,
     *     type?: string,
     *     statut?: string,
     *     heure_reelle?: string,
     *     heure_effective?: string,
     *     ajustement_applique?: bool,
     * }
     */
    public function record(
        User $user,
        Agence $agence,
        float $latitude,
        float $longitude,
        ?string $requestedType,
        bool $qrVerified,
        bool $biometricOk,
        array $metaExtra = [],
    ): array {
        $clockedAt = Carbon::now();

        if (PointageJourSemaine::isJourQrInactif($clockedAt)) {
            return ['ok' => false, 'message' => PointageJourSemaine::messageQrInactif($clockedAt)];
        }

        $enrolment = PointageEnrolment::ensureAuthorized($user, $agence, $clockedAt);
        if (! $enrolment['ok']) {
            return ['ok' => false, 'message' => $enrolment['message'] ?? 'Pointage refusé.'];
        }

        $resolved = $this->horaires->resolveType($clockedAt, $requestedType);
        if (! $resolved['ok']) {
            return ['ok' => false, 'message' => $resolved['message'] ?? 'Pointage refusé.'];
        }

        $type = (string) $resolved['type'];

        $today = Carbon::today();
        // Un scan réel remplace les pointages synthétiques (férié auto / déclaration RH).
        $this->purgeSyntheticPunchesForType($user->id, $type, $today);

        $exists = Pointage::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereDate('clocked_at', $today)
            ->get()
            ->contains(fn (Pointage $p) => ! $p->isSynthetic());

        if ($exists) {
            $libelle = $type === 'arrivee' ? 'd\'arrivée' : 'de départ';

            return ['ok' => false, 'message' => 'Un pointage '.$libelle.' existe déjà pour aujourd\'hui.'];
        }

        $deviceFingerprint = PointageDeviceDayGuard::fingerprintFromMeta($metaExtra);
        // Borne virtuelle : plusieurs comptes partagent le même appareil le même jour.
        if (! $agence->isVirtual()) {
            $deviceGuard = PointageDeviceDayGuard::assertAvailableForUser($user, $deviceFingerprint, $today);
            if (! $deviceGuard['ok']) {
                return ['ok' => false, 'message' => $deviceGuard['message'] ?? PointageDeviceDayGuard::BLOCK_MESSAGE];
            }
        }

        $effective = $this->horaires->computeEffectivePunch($clockedAt, $type, $resolved['plage'] ?? null);

        $meta = array_merge($metaExtra, [
            'heure_reelle' => $effective['heure_reelle'],
            'heure_effective' => $effective['heure_effective'],
            'heure_effective_at' => $effective['heure_effective_at']->toIso8601String(),
            'ajustement_applique' => $effective['ajustement_applique'],
            'plage' => $effective['plage'],
            'requested_type' => $resolved['requested_type'] ?? null,
            'type_auto_corrected' => $resolved['type_auto_corrected'] ?? false,
        ]);
        if ($deviceFingerprint !== null) {
            $meta['device_fingerprint'] = $deviceFingerprint;
        }

        $pointage = Pointage::query()->create([
            'user_id' => $user->id,
            'agence_id' => $agence->id,
            'type' => $type,
            'clocked_at' => $clockedAt,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'qr_verified' => $qrVerified,
            'biometric_ok' => $biometricOk,
            'statut' => $effective['statut'],
            'meta' => $meta,
        ]);

        $libelle = $type === 'arrivee' ? 'd\'arrivée' : 'de départ';
        $message = 'Pointage '.$libelle.' enregistré ('.$effective['heure_effective']
            .($effective['ajustement_applique'] ? ', heure ajustée' : ', heure réelle').').';
        if ($resolved['type_auto_corrected'] ?? false) {
            $message .= ' Type détecté automatiquement selon l\'heure (plage '
                .($type === 'arrivee' ? config('pointage.plage_arrivee_debut').'–'.config('pointage.plage_arrivee_fin') : config('pointage.plage_depart_debut').'–'.config('pointage.plage_depart_fin'))
                .').';
        }

        return [
            'ok' => true,
            'pointage' => $pointage,
            'type' => $type,
            'statut' => $effective['statut'],
            'heure_reelle' => $effective['heure_reelle'],
            'heure_effective' => $effective['heure_effective'],
            'ajustement_applique' => $effective['ajustement_applique'],
            'type_auto_corrected' => $resolved['type_auto_corrected'] ?? false,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(array $result): array
    {
        if (! ($result['ok'] ?? false)) {
            return ['message' => $result['message'] ?? 'Pointage refusé.'];
        }

        /** @var Pointage $p */
        $p = $result['pointage'];

        return [
            'id' => (string) $p->id,
            'type' => $result['type'],
            'pointage_type' => $result['type'],
            'type_auto_corrected' => $result['type_auto_corrected'] ?? false,
            'statut' => $result['statut'],
            'recorded_at' => $p->clocked_at->toIso8601String(),
            'recordedAt' => $p->clocked_at->toIso8601String(),
            'heure_reelle' => $result['heure_reelle'],
            'heure_effective' => $result['heure_effective'],
            'ajustement_applique' => $result['ajustement_applique'],
            'ajustementApplique' => $result['ajustement_applique'],
            'message' => $result['message'],
        ];
    }

    public function requestMeta(Request $request): array
    {
        return array_filter(array_merge(
            PointageDeviceDayGuard::metaFromRequest($request),
            [
                'user_agent' => $request->userAgent(),
                'pointrust' => $request->header('X-Pointrust-App') ? true : null,
            ]
        ), static fn ($v) => $v !== null && $v !== '');
    }

    private function purgeSyntheticPunchesForType(int $userId, string $type, Carbon $day): void
    {
        $synthetics = Pointage::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->whereDate('clocked_at', $day)
            ->get()
            ->filter(fn (Pointage $p) => $p->isSynthetic());

        if ($synthetics->isEmpty()) {
            return;
        }

        $attendanceType = $type === 'arrivee' ? 'checkin' : 'checkout';
        Attendance::query()
            ->where('user_id', $userId)
            ->whereIn('type', [$attendanceType, $type])
            ->whereDate('recorded_at', $day)
            ->where(function ($q): void {
                $q->where('qr_payload', 'like', 'declaration:%')
                    ->orWhereNull('qr_payload')
                    ->orWhere('qr_payload', '');
            })
            ->delete();

        Pointage::query()->whereIn('id', $synthetics->pluck('id'))->delete();
    }
}
