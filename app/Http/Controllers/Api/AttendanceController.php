<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AttendanceHistoryRequest;
use App\Http\Requests\Api\AttendanceStoreRequest;
use App\Models\Attendance;
use App\Models\Notification;
use App\Models\PointrustQrSession;
use App\Services\Pointage\PointagePunchService;
use App\Services\PointageQrScanResolver;
use App\Support\PointageDeclarationCouverture;
use App\Support\PointageEnrolment;
use App\Support\PointageGeofencing;
use App\Support\PointageJourSemaine;
use App\Support\PointageQrScanUrl;
use App\Services\PointageOtpService;
use App\Support\PointageDeviceDayGuard;
use App\Support\PointageVirtualEmailAuth;
use App\Support\PointageVirtualKioskDevice;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly PointageQrScanResolver $qrScanResolver,
        private readonly PointageOtpService $otpService,
    ) {}

    public function checkin(AttendanceStoreRequest $request, PointagePunchService $punchService): JsonResponse
    {
        return $this->attend($request, null, 'checkin', $punchService);
    }

    public function checkout(AttendanceStoreRequest $request, PointagePunchService $punchService): JsonResponse
    {
        return $this->attend($request, null, 'checkout', $punchService);
    }

    public function history(AttendanceHistoryRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $validated = $request->validated();

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : Carbon::now()->endOfDay();

        $rows = Attendance::query()
            ->where('user_id', $user->id)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get();

        $byDate = $rows->groupBy(fn (Attendance $a) => $a->recorded_at->toDateString());

        // Une seule requête pour toutes les déclarations justifiantes de la période (évite N+1).
        $justificationsByDay = PointageDeclarationCouverture::labelsPourUserPeriode(
            (int) $user->id,
            $from,
            $to,
        );

        $data = [];
        foreach (CarbonPeriod::create($from->toDateString(), $to->toDateString()) as $day) {
            $dateStr = $day->toDateString();
            $dayRows = $byDate->get($dateStr, collect());
            $checkin = $dayRows->where('type', 'checkin')->sortBy('recorded_at')->first();
            $checkout = $dayRows->where('type', 'checkout')->sortByDesc('recorded_at')->first();

            $checkInIso = $checkin?->recorded_at?->toIso8601String();
            $checkOutIso = $checkout?->recorded_at?->toIso8601String();

            $status = 'absent';
            if ($checkInIso !== null && $checkOutIso !== null) {
                $status = 'present';
            } elseif ($checkInIso !== null) {
                $status = 'partial';
            }

            $couverture = null;
            if ($status === 'absent') {
                $label = $justificationsByDay[$dateStr] ?? null;
                if (is_string($label) && $label !== '') {
                    $status = 'justifie';
                    $couverture = $label;
                }
            }

            $data[] = [
                'id' => $checkin?->id ?? $checkout?->id,
                'date' => $dateStr,
                'check_in' => $checkInIso,
                'check_out' => $checkOutIso,
                'status' => $status,
                'justification_label' => $couverture,
            ];
        }

        usort($data, static fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));

        return response()->json(['data' => $data]);
    }

    private function hasOpenCheckinToday(int $userId): bool
    {
        $start = Carbon::today()->startOfDay();
        $end = Carbon::today()->endOfDay();
        $checkins = Attendance::query()
            ->where('user_id', $userId)
            ->where('type', 'checkin')
            ->whereBetween('recorded_at', [$start, $end])
            ->count();
        $checkouts = Attendance::query()
            ->where('user_id', $userId)
            ->where('type', 'checkout')
            ->whereBetween('recorded_at', [$start, $end])
            ->count();

        return $checkins > $checkouts;
    }

    private function attend(AttendanceStoreRequest $request, ?string $preferredType, string $apiType, PointagePunchService $punchService): JsonResponse
    {
        if (PointageJourSemaine::isJourQrInactif()) {
            return response()->json([
                'message' => PointageJourSemaine::messageQrInactif(),
                'error' => 'qr_inactif_jour',
                'window' => PointageJourSemaine::windowInfo(),
            ], 422);
        }

        $validated = $request->validated();
        $sessionUser = $request->user();
        $qrContent = PointageQrScanUrl::normalizeScannedContent($validated['qr_payload']);

        // Compte borne : pas d’exigence d’enrôlement du token Sanctum pour le QR v1 static.
        $resolvedDetailed = $this->qrScanResolver->resolveDetailed($qrContent, null);
        if (($resolvedDetailed['ok'] ?? false) !== true) {
            // Repli : QR v2 lié à un compte (sites classiques).
            $resolvedDetailed = $this->qrScanResolver->resolveDetailed($qrContent, $sessionUser);
        }
        if (($resolvedDetailed['ok'] ?? false) !== true) {
            return response()->json([
                'message' => $resolvedDetailed['message'] ?? 'QR Code invalide ou expiré',
                'error' => $resolvedDetailed['error'] ?? 'invalid_qr',
            ], 422);
        }

        $agence = $resolvedDetailed['agence'];
        $resolved = $resolvedDetailed;

        if (! ($agence->pointage_qr_enabled ?? true)) {
            return response()->json([
                'message' => 'Le QR Code de ce site est temporairement désactivé',
                'error' => 'qr_disabled',
            ], 422);
        }

        $punchUser = $sessionUser;
        $virtualOtpOk = false;
        $virtualEmail = null;

        if ($agence->isVirtual()) {
            $deviceMeta = PointageDeviceDayGuard::metaFromRequest($request);
            $serial = $deviceMeta['serial_number']
                ?? $deviceMeta['device_fingerprint']
                ?? ($validated['serial_number'] ?? null);
            $deviceCheck = PointageVirtualKioskDevice::assertAuthorized($agence, $serial, true);
            if (! $deviceCheck['ok']) {
                return response()->json([
                    'message' => $deviceCheck['message'] ?? PointageVirtualKioskDevice::REQUIRED_MESSAGE,
                    'error' => 'kiosk_device_forbidden',
                ], 403);
            }

            $employeeCheck = PointageVirtualEmailAuth::resolveEnrolledPunchUser(
                $agence,
                $validated['email'] ?? null
            );
            if (! $employeeCheck['ok'] || ! isset($employeeCheck['user'])) {
                return response()->json([
                    'message' => $employeeCheck['message'] ?? PointageVirtualEmailAuth::REQUIRED_MESSAGE,
                    'error' => 'email_required',
                    'auth_mode' => 'email_otp',
                ], 422);
            }

            $punchUser = $employeeCheck['user'];
            $virtualEmail = $employeeCheck['email']
                ?? PointageVirtualEmailAuth::normalize((string) $validated['email']);

            $otpCheck = $this->otpService->consumeVirtualPunchOtp(
                $punchUser,
                $agence,
                $qrContent,
                (string) ($validated['otp_code'] ?? ''),
                $virtualEmail,
            );
            if (! $otpCheck['ok']) {
                return response()->json([
                    'message' => $otpCheck['message'] ?? 'Code OTP invalide.',
                    'error' => 'otp_invalid',
                    'auth_mode' => 'email_otp',
                ], 422);
            }

            $virtualOtpOk = true;
        } else {
            $enrolment = PointageEnrolment::ensureAuthorized($sessionUser, $agence);
            if (! $enrolment['ok']) {
                return response()->json([
                    'message' => $enrolment['message'],
                    'error' => $enrolment['reason'] ?? 'not_enrolled',
                ], 403);
            }
        }

        $geo = PointageGeofencing::validate(
            $agence,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        );

        if (! $geo['ok']) {
            return response()->json(PointageGeofencing::toJsonError($geo), 422);
        }

        $requested = $validated['type'] ?? $preferredType;

        $biometricOk = $virtualOtpOk || (($validated['biometric_nonce'] ?? '') !== '');

        $punch = $punchService->record(
            $punchUser,
            $agence,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $requested,
            true,
            $biometricOk,
            array_merge($punchService->requestMeta($request), [
                'api_type' => $apiType,
                'qr_payload' => $qrContent,
                'is_virtual' => $agence->isVirtual(),
                'auth_mode' => $agence->isVirtual() ? 'email_otp' : 'biometric',
                'email' => $virtualEmail,
                'kiosk_session_user_id' => $agence->isVirtual() ? $sessionUser->id : null,
            ]),
        );

        if (! $punch['ok']) {
            return response()->json(['message' => $punch['message'] ?? 'Pointage refusé'], 422);
        }

        /** @var \App\Models\Pointage $pointage */
        $pointage = $punch['pointage'];
        $recordedAt = $pointage->clocked_at;

        $apiAttendanceType = ($punch['type'] ?? '') === 'arrivee' ? 'checkin' : 'checkout';
        Attendance::query()->create([
            'user_id' => $punchUser->id,
            'type' => $apiAttendanceType,
            'qr_payload' => $qrContent,
            'latitude' => (float) $validated['latitude'],
            'longitude' => (float) $validated['longitude'],
            'biometric_nonce' => $validated['biometric_nonce'] ?? null,
            'recorded_at' => $recordedAt,
        ]);

        if ($resolved['qr_kind'] === 'pointrust_session' && isset($resolved['session'])) {
            /** @var PointrustQrSession $session */
            $session = $resolved['session'];
            $session->update([
                'status' => 'used',
                'used_by_user_id' => $punchUser->id,
                'used_at' => $recordedAt,
            ]);
        }

        Notification::query()->create([
            'user_id' => $punchUser->id,
            'title' => 'Pointage enregistré',
            'body' => ($punch['message'] ?? 'Pointage enregistré').' — '.$recordedAt->format('d/m/Y H\hi'),
            'read' => false,
        ]);

        return response()->json(array_merge(
            $punchService->toApiPayload($punch),
            [
                'type' => $apiAttendanceType,
                'pointage_type' => $punch['type'],
                'geofencing' => [
                    'distance_metres' => $geo['distance_metres'] ?? null,
                    'rayon_autorise_metres' => $geo['rayon_autorise_metres'] ?? null,
                ],
            ]
        ));
    }
}
