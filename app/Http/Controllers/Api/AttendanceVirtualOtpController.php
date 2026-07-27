<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PointageOtpService;
use App\Services\PointageQrScanResolver;
use App\Support\PointageDeviceDayGuard;
use App\Support\PointageGeofencing;
use App\Support\PointageQrScanUrl;
use App\Support\PointageVirtualEmailAuth;
use App\Support\PointageVirtualKioskDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceVirtualOtpController extends Controller
{
    public function __construct(
        private readonly PointageQrScanResolver $qrScanResolver,
        private readonly PointageOtpService $otpService,
    ) {}

    /**
     * Borne virtuelle (compte par défaut) : envoie un OTP à l’e-mail d’un collaborateur enrôlé.
     * Le pointage sera enregistré au nom de ce collaborateur, pas du compte borne.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_payload' => ['required', 'string', 'min:8', 'max:512'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'device_id' => ['nullable', 'string', 'max:128'],
            'serial_number' => ['nullable', 'string', 'max:128'],
        ]);

        $qrContent = PointageQrScanUrl::normalizeScannedContent((string) $validated['qr_payload']);

        // QR résolu sans exiger l’enrôlement du compte borne.
        $resolved = $this->qrScanResolver->resolveDetailed($qrContent, null);
        if (! ($resolved['ok'] ?? false) || ! isset($resolved['agence'])) {
            return response()->json([
                'message' => $resolved['message'] ?? 'QR Code invalide.',
                'error' => $resolved['error'] ?? 'invalid_qr',
            ], 422);
        }

        /** @var \App\Models\Agence $agence */
        $agence = $resolved['agence'];

        if (! $agence->isVirtual()) {
            return response()->json([
                'message' => 'Ce flux OTP est réservé aux agences virtuelles.',
                'error' => 'not_virtual',
            ], 422);
        }

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

        $employeeCheck = PointageVirtualEmailAuth::resolveEnrolledPunchUser($agence, $validated['email']);
        if (! $employeeCheck['ok'] || ! isset($employeeCheck['user'])) {
            return response()->json([
                'message' => $employeeCheck['message'] ?? PointageVirtualEmailAuth::UNKNOWN_MESSAGE,
                'error' => 'employee_not_found',
            ], 422);
        }

        /** @var \App\Models\User $employee */
        $employee = $employeeCheck['user'];
        $email = $employeeCheck['email'] ?? PointageVirtualEmailAuth::normalize($validated['email']);

        if (isset($validated['latitude'], $validated['longitude'])) {
            $geo = PointageGeofencing::validate(
                $agence,
                (float) $validated['latitude'],
                (float) $validated['longitude'],
            );
            if (! $geo['ok']) {
                return response()->json(PointageGeofencing::toJsonError($geo), 422);
            }
        }

        $result = $this->otpService->sendVirtualPunchOtp($employee, $agence, $qrContent, $email);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'] ?? 'Impossible d’envoyer le code.',
                'error' => 'otp_send_failed',
            ], 422);
        }

        $payload = [
            'ok' => true,
            'message' => $result['message'] ?? 'Code OTP envoyé.',
            'email' => $email,
            'auth_mode' => 'email_otp',
            'kiosk_device_bound' => (bool) ($deviceCheck['bound'] ?? false),
        ];

        if ($result['via_log_fallback'] ?? false) {
            $payload['otp_delivery'] = 'log';
        }

        return response()->json($payload);
    }
}
