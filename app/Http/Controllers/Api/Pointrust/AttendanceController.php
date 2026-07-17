<?php

namespace App\Http\Controllers\Api\Pointrust;

use App\Http\Controllers\Controller;
use App\Models\Agence;
use App\Models\Pointage;
use App\Models\PointrustAppNotification;
use App\Models\PointrustQrSession;
use App\Services\Pointage\PointagePunchService;
use App\Services\Pointrust\PointrustQrPayloadService;
use App\Support\PointageEnrolment;
use App\Support\PointageGeofencing;
use App\Support\PointageJourSemaine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function checkin(Request $request, PointagePunchService $punchService): JsonResponse
    {
        return $this->attend($request, null, 'checkin', "Pointage d'entrée enregistré", $punchService);
    }

    public function checkout(Request $request, PointagePunchService $punchService): JsonResponse
    {
        return $this->attend($request, null, 'checkout', 'Pointage de sortie enregistré', $punchService);
    }

    private function attend(Request $request, ?string $preferredType, string $apiType, string $successMessage, PointagePunchService $punchService): JsonResponse
    {
        if (PointageJourSemaine::isJourQrInactif()) {
            return response()->json([
                'message' => PointageJourSemaine::messageQrInactif(),
                'error' => 'qr_inactif_jour',
                'window' => PointageJourSemaine::windowInfo(),
            ], 422);
        }

        $validated = $request->validate([
            'qr_payload' => 'required|string|max:512',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'biometric_nonce' => 'nullable|string|max:2048',
            'device_id' => 'nullable|string|max:128',
            'serial_number' => 'nullable|string|max:128',
            'type' => 'nullable|string|in:checkin,checkout,arrivee,depart',
        ]);

        if (! empty($validated['device_id'])) {
            $request->merge([
                'device_id' => \App\Support\MobileDeviceId::normalize((string) $validated['device_id']),
            ]);
        }
        if (! empty($validated['serial_number'])) {
            $request->merge([
                'serial_number' => \App\Support\MobileDeviceId::normalize((string) $validated['serial_number']),
            ]);
        }

        $parsed = PointrustQrPayloadService::parse($validated['qr_payload']);
        if ($parsed === null) {
            return response()->json(['message' => 'QR Code invalide ou expiré'], 422);
        }
        [$sessionId, $timestamp, $signature] = $parsed;

        $secret = (string) config('pointrust.jwt_secret');
        if (! PointrustQrPayloadService::verifySignature($sessionId, $timestamp, $signature, $secret)) {
            return response()->json(['message' => 'QR Code invalide ou expiré'], 422);
        }

        $maxSkew = (int) config('pointrust.qr_ttl_seconds') + 45;
        if (abs(time() - $timestamp) > $maxSkew) {
            return response()->json(['message' => 'QR Code invalide ou expiré'], 422);
        }

        $session = PointrustQrSession::query()->find($sessionId);
        if (! $session || $session->status !== 'pending') {
            return response()->json(['message' => 'QR Code invalide ou expiré'], 422);
        }

        if (Carbon::now()->greaterThan($session->expires_at)) {
            $session->update(['status' => 'expired']);

            return response()->json(['message' => 'QR Code invalide ou expiré'], 422);
        }

        /** @var Agence|null $agence */
        $agence = $session->agence;
        if (! $agence || ! $agence->actif) {
            return response()->json(['message' => 'Site de pointage inactif'], 422);
        }

        $user = $request->user();
        $enrolment = PointageEnrolment::ensureAuthorized($user, $agence);
        if (! $enrolment['ok']) {
            return response()->json([
                'message' => $enrolment['message'],
                'error' => $enrolment['reason'] ?? 'not_enrolled',
            ], 403);
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

        $punch = $punchService->record(
            $user,
            $agence,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            $requested,
            true,
            ($validated['biometric_nonce'] ?? '') !== '',
            array_merge($punchService->requestMeta($request), [
                'pointrust' => true,
                'api_type' => $apiType,
                'pointrust_qr_session_id' => $sessionId,
                'biometric_nonce' => $validated['biometric_nonce'] ?? null,
            ]),
        );

        if (! $punch['ok']) {
            return response()->json(['message' => $punch['message'] ?? 'Pointage refusé'], 422);
        }

        /** @var Pointage $record */
        $record = $punch['pointage'];
        $clockedAt = $record->clocked_at;

        $session->update([
            'status' => 'used',
            'used_by_user_id' => $user->id,
            'used_at' => $clockedAt,
        ]);

        PointrustAppNotification::query()->create([
            'user_id' => $user->id,
            'title' => 'Pointage enregistré',
            'body' => ($punch['message'] ?? $successMessage).' — '.$clockedAt->format('d/m/Y H\hi'),
            'read' => false,
        ]);

        return response()->json(array_merge(
            $punchService->toApiPayload($punch),
            ['type' => $apiType, 'pointage_type' => $punch['type']]
        ));
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        $rows = Pointage::query()
            ->where('user_id', $user->id)
            ->whereBetween('clocked_at', [$from, $to])
            ->orderBy('clocked_at')
            ->get();

        $grouped = $rows->groupBy(fn (Pointage $p) => $p->clocked_at->toDateString());
        $data = [];
        foreach ($grouped as $date => $pointages) {
            $arr = $pointages->where('type', 'arrivee')->sortBy('clocked_at')->first();
            $dep = $pointages->where('type', 'depart')->sortByDesc('clocked_at')->first();
            $checkIn = $arr?->clocked_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            $checkOut = $dep?->clocked_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            $status = ($checkIn && $checkOut) ? 'present' : 'incomplete';
            $data[] = [
                'id' => (string) (($arr ?? $dep)?->id ?? $pointages->first()->id),
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'checkIn' => $checkIn,
                'checkOut' => $checkOut,
                'status' => $status,
            ];
        }

        usort($data, static fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return response()->json([
            'data' => $data,
        ]);
    }
}
