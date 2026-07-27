<?php

namespace App\Services;

use App\Models\Agence;
use App\Models\User;
use App\Support\PointageQrBase64;
use App\Support\PointageQrScanUrl;
use Carbon\Carbon;

class PointageQrService
{
    private const PAYLOAD_VERSION_BOUND = 2;

    public function ensureSecret(Agence $agence): string
    {
        if (! $agence->pointage_qr_secret) {
            $agence->pointage_qr_secret = bin2hex(random_bytes(32));
            $agence->save();
        }

        return $agence->pointage_qr_secret;
    }

    /**
     * Jeton signé pour le site. Si un utilisateur est fourni (flux employé connecté),
     * le jeton est lié à son identité (empêche la réutilisation du même QR par un autre compte).
     *
     * @return array{token: string, expires_at: string, scan_url: string, qr_content: string}
     */
    public function mintToken(Agence $agence, ?User $user = null): array
    {
        if ($user !== null && ! $agence->isEnrolledForPointageQr()) {
            throw new \InvalidArgumentException('Cette agence n’est pas enrôlée au pointage QR.');
        }

        $secret = $this->ensureSecret($agence);
        // Virtuel / static : QR longue durée (borne partagée imprimable). Dynamique : TTL court.
        $isLongLived = $agence->isVirtual() || ($agence->pointage_qr_type === 'static');
        $ttl = $isLongLived
            ? (int) config('pointage.qr_static_ttl_seconds', 86400 * 365 * 10)
            : (int) config('pointage.qr_dynamic_ttl_seconds', 300);

        $exp = Carbon::now()->addSeconds(max(60, $ttl))->timestamp;
        $payload = [
            'v' => $user ? self::PAYLOAD_VERSION_BOUND : 1,
            'aid' => $agence->id,
            'exp' => $exp,
            'iat' => Carbon::now()->timestamp,
        ];

        if ($user) {
            $user->loadMissing('profil');
            $payload['uid'] = $user->id;
            $emailNorm = $this->bindEmailNormalized($user);
            $phoneNorm = $this->normalizedPhoneDigits($user->profil?->telephone);
            $payload['bind'] = hash_hmac('sha256', $user->id.'|'.$emailNorm.'|'.$phoneNorm.'|'.$exp, $secret);
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $sig = hash_hmac('sha256', $body, $secret);
        $token = rtrim(strtr(base64_encode($body.'|'.$sig), '+/', '-_'), '=');

        $scanUrl = PointageQrScanUrl::forPointageToken($token);
        $qrContent = PointageQrScanUrl::encodeAsUrl() ? $scanUrl : $token;

        return [
            'token' => $token,
            'expires_at' => Carbon::createFromTimestamp($exp)->toIso8601String(),
            'scan_url' => $scanUrl,
            'qr_content' => $qrContent,
        ];
    }

    /**
     * Vérifie le jeton pour un pointage employé : signature, site, expiration, et liaison utilisateur (v2).
     */
    public function verifyToken(string $token, Agence $agence, User $user): bool
    {
        $token = PointageQrScanUrl::normalizeScannedContent($token);

        if ($token === '' || ! $agence->isEnrolledForPointageQr()) {
            return false;
        }

        $secret = $this->ensureSecret($agence);
        $decoded = PointageQrBase64::urlSafeDecode($token);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            return false;
        }

        [$body, $sig] = explode('|', $decoded, 2);
        $expected = hash_hmac('sha256', $body, $secret);
        if (! hash_equals($expected, $sig)) {
            return false;
        }

        try {
            /** @var array{v?: int|float, aid?: int, exp?: int, uid?: int, bind?: string} $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        if (($data['aid'] ?? null) !== $agence->id) {
            return false;
        }

        if (! $this->isLongLivedQr($agence) && ($data['exp'] ?? 0) < Carbon::now()->timestamp) {
            return false;
        }

        $version = (int) ($data['v'] ?? 1);
        if ($version >= self::PAYLOAD_VERSION_BOUND) {
            if (($data['uid'] ?? null) !== $user->id) {
                return false;
            }
            $user->loadMissing('profil');
            $emailNorm = $this->bindEmailNormalized($user);
            $phoneNorm = $this->normalizedPhoneDigits($user->profil?->telephone);
            $expectedBind = hash_hmac(
                'sha256',
                $user->id.'|'.$emailNorm.'|'.$phoneNorm.'|'.($data['exp'] ?? 0),
                $secret
            );
            if (! isset($data['bind']) || ! hash_equals($expectedBind, (string) $data['bind'])) {
                return false;
            }
        } else {
            // Jetons sans liaison utilisateur (aperçu admin uniquement — non acceptés au pointage)
            return false;
        }

        return true;
    }

    /**
     * E-mail de liaison QR / OTP : fiche RH puis compte utilisateur (identique à PointageOtpService).
     */
    private function bindEmailNormalized(User $user): string
    {
        return strtolower(trim((string) ($user->profil?->email ?: $user->email)));
    }

    public function isLongLivedQr(Agence $agence): bool
    {
        return $agence->isVirtual() || ($agence->pointage_qr_type === 'static');
    }

    /**
     * Chiffres uniquement, pour lier le QR au téléphone professionnel du profil.
     */
    public function normalizedPhoneDigits(?string $telephone): string
    {
        return preg_replace('/\D/', '', (string) $telephone) ?? '';
    }
}
