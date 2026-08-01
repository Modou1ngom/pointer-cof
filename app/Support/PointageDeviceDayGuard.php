<?php

namespace App\Support;

use App\Models\Pointage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Un même appareil ne peut servir qu’à un seul compte pour pointer le même jour.
 */
final class PointageDeviceDayGuard
{
    public const BLOCK_MESSAGE = 'Cet appareil a déjà été utilisé pour un pointage aujourd\'hui par un autre compte.';

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function fingerprintFromMeta(array $meta): ?string
    {
        foreach (['device_fingerprint', 'serial_number', 'device_id'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '' && self::isUsableFingerprint($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array{device_id?: string, serial_number?: string, device_fingerprint?: string}
     */
    public static function metaFromRequest(Request $request): array
    {
        $rawDeviceId = (string) (
            $request->input('device_id')
            ?? $request->input('deviceId')
            ?? $request->header('X-Device-Id')
            ?? $request->header('Device-Id')
            ?? ''
        );
        $rawSerial = (string) (
            $request->input('serial_number')
            ?? $request->input('serialNumber')
            ?? $request->header('X-Device-Serial')
            ?? $request->header('Device-Serial')
            ?? ''
        );

        $deviceId = MobileDeviceId::normalize($rawDeviceId);
        $serial = MobileDeviceId::normalize($rawSerial);

        $fingerprint = null;
        if (self::isUsableFingerprint($serial)) {
            $fingerprint = $serial;
        } elseif (self::isUsableFingerprint($deviceId)) {
            $fingerprint = $deviceId;
        }

        return array_filter([
            'device_id' => self::isUsableFingerprint($deviceId) ? $deviceId : null,
            'serial_number' => self::isUsableFingerprint($serial) ? $serial : null,
            'device_fingerprint' => $fingerprint,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function assertAvailableForUser(User $user, ?string $fingerprint, ?Carbon $day = null): array
    {
        $fingerprint = trim((string) $fingerprint);
        if ($fingerprint === '' || ! self::isUsableFingerprint($fingerprint)) {
            return ['ok' => true];
        }

        $day = ($day ?? Carbon::today())->copy()->startOfDay();

        $usedByOther = Pointage::query()
            ->whereDate('clocked_at', $day)
            ->where('user_id', '!=', $user->id)
            ->where(function ($q) use ($fingerprint) {
                $q->where('meta->device_fingerprint', $fingerprint)
                    ->orWhere('meta->device_id', $fingerprint)
                    ->orWhere('meta->serial_number', $fingerprint);
            })
            ->exists();

        if ($usedByOther) {
            return ['ok' => false, 'message' => self::BLOCK_MESSAGE];
        }

        return ['ok' => true];
    }

    public static function isUsableFingerprint(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // Empreintes web dérivées du User-Agent : trop faibles / partagées.
        if (str_starts_with($value, 'web_')) {
            return false;
        }

        // Build.ID / serials génériques Android : partagés entre téléphones → faux positifs.
        if (self::isWeakSharedIdentifier($value)) {
            return false;
        }

        return MobileDeviceSerialResolver::isPlausibleDeviceSerial($value);
    }

    /**
     * Identifiants non uniques (firmware Build.ID, placeholders OEM / émulateur).
     */
    public static function isWeakSharedIdentifier(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        $lower = strtolower($value);
        $placeholders = [
            'unknown',
            'unauthorized',
            'null',
            'nil',
            'none',
            '0',
            '0123456789abcdef',
            'ffffffffffffffff',
            'android',
            'emulator',
        ];
        if (in_array($lower, $placeholders, true)) {
            return true;
        }

        // Build.FINGERPRINT (ex. google/sdk_gphone64_x86_64/...)
        if (str_contains($value, '/') && str_contains($value, ':')) {
            return true;
        }

        // Build.ID typique : "TP1A.220624.014", "QP1A.190711.020"
        if (preg_match('/^[A-Za-z0-9]+\.\d{5,}\./', $value) === 1) {
            return true;
        }

        // Court label Build.ID (ex. "N2G48H") — trop souvent partagé entre modèles.
        // ANDROID_ID réel = 16 hex ; on ne rejette pas les hex ≥ 14.
        if (preg_match('/^[A-Za-z0-9]{4,12}$/', $value) === 1
            && preg_match('/^[a-f0-9]{14,}$/i', $value) !== 1) {
            return true;
        }

        return false;
    }
}
