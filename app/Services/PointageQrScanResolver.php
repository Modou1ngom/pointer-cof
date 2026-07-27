<?php

namespace App\Services;

use App\Models\Agence;
use App\Models\PointrustQrSession;
use App\Models\User;
use App\Services\Pointrust\PointrustQrPayloadService;
use App\Support\PointageQrBase64;
use App\Support\PointageQrScanUrl;
use Carbon\Carbon;

/**
 * Résout le site (agence) à partir d’un QR scanné : session POINTRUST ou jeton site pointage.
 */
final class PointageQrScanResolver
{
    public function __construct(
        private readonly PointageQrService $pointageQr,
    ) {}

    /**
     * @return array{agence: Agence, qr_kind: 'pointrust_session'|'pointage_site', session?: PointrustQrSession}|null
     */
    public function resolve(string $rawQr, ?User $user = null): ?array
    {
        $content = PointageQrScanUrl::normalizeScannedContent(trim($rawQr));
        if ($content === '') {
            return null;
        }

        $fromPointrust = $this->resolvePointrustSession($content);
        if ($fromPointrust !== null) {
            return $fromPointrust;
        }

        $agence = $this->resolvePointageSiteToken($content, $user);
        if ($agence === null) {
            return null;
        }

        return [
            'agence' => $agence,
            'qr_kind' => 'pointage_site',
        ];
    }

    /**
     * Comme resolve(), avec motif d’échec pour l’API mobile.
     *
     * @return array{ok: true, agence: Agence, qr_kind: string, session?: PointrustQrSession}|array{ok: false, error: string, message: string}
     */
    public function resolveDetailed(string $rawQr, ?User $user = null): array
    {
        $content = PointageQrScanUrl::normalizeScannedContent(trim($rawQr));
        if ($content === '') {
            return [
                'ok' => false,
                'error' => 'empty_qr',
                'message' => 'QR Code vide ou illisible.',
            ];
        }

        $fromPointrust = $this->resolvePointrustSession($content);
        if ($fromPointrust !== null) {
            return array_merge(['ok' => true], $fromPointrust);
        }

        $decoded = $this->decodePointageTokenPayload($content);
        if ($decoded === null) {
            return [
                'ok' => false,
                'error' => 'invalid_qr_format',
                'message' => 'QR Code invalide (format non reconnu). Vérifiez que l’app pointe vers le même serveur que la borne.',
            ];
        }

        $agenceId = (int) ($decoded['aid'] ?? 0);
        $agence = $agenceId > 0 ? Agence::query()->find($agenceId) : null;
        if ($agence === null) {
            return [
                'ok' => false,
                'error' => 'agence_not_found',
                'message' => 'Site introuvable pour ce QR. L’app et la borne doivent utiliser le même environnement (local ou production).',
            ];
        }

        // Expiration : uniquement pour les QR dynamiques. Virtuel / static = longue durée.
        $isLongLived = $agence->isVirtual() || ($agence->pointage_qr_type === 'static');
        if (! $isLongLived && ($decoded['exp'] ?? 0) < Carbon::now()->timestamp) {
            return [
                'ok' => false,
                'error' => 'qr_expired',
                'message' => 'QR Code expiré. Actualisez la borne / le kiosk puis rescanner.',
            ];
        }

        if (! $agence->actif) {
            return [
                'ok' => false,
                'error' => 'agence_inactive',
                'message' => 'Le site « '.$agence->nom.' » est inactif.',
            ];
        }

        if (! $agence->isEnrolledForPointageQr()) {
            return [
                'ok' => false,
                'error' => 'agence_not_enrolled',
                'message' => 'Le site « '.$agence->nom.' » n’est pas enrôlé au pointage QR.',
            ];
        }

        $resolved = $this->resolvePointageSiteToken($content, $user);
        if ($resolved === null) {
            return [
                'ok' => false,
                'error' => 'invalid_qr_signature',
                'message' => 'QR Code invalide (signature). Régénérez le QR sur la borne du même serveur que l’API mobile.',
            ];
        }

        return [
            'ok' => true,
            'agence' => $resolved,
            'qr_kind' => 'pointage_site',
        ];
    }

    /**
     * @return array{agence: Agence, qr_kind: 'pointrust_session', session: PointrustQrSession}|null
     */
    private function resolvePointrustSession(string $content): ?array
    {
        $parsed = PointrustQrPayloadService::parse($content);
        if ($parsed === null) {
            return null;
        }

        [$sessionId, $timestamp, $signature] = $parsed;
        $secret = (string) config('pointrust.jwt_secret');
        if (! PointrustQrPayloadService::verifySignature($sessionId, $timestamp, $signature, $secret)) {
            return null;
        }

        $maxSkew = (int) config('pointrust.qr_ttl_seconds', 120) + 45;
        if (abs(time() - $timestamp) > $maxSkew) {
            return null;
        }

        $session = PointrustQrSession::query()->with('agence')->find($sessionId);
        if ($session === null || $session->status !== 'pending') {
            return null;
        }

        if (Carbon::now()->greaterThan($session->expires_at)) {
            $session->update(['status' => 'expired']);

            return null;
        }

        $agence = $session->agence;
        if ($agence === null || ! $agence->actif) {
            return null;
        }

        return [
            'agence' => $agence,
            'qr_kind' => 'pointrust_session',
            'session' => $session,
        ];
    }

    private function resolvePointageSiteToken(string $token, ?User $user): ?Agence
    {
        $decoded = $this->decodePointageTokenPayload($token);
        if ($decoded === null) {
            return null;
        }

        $agenceId = (int) ($decoded['aid'] ?? 0);
        if ($agenceId <= 0) {
            return null;
        }

        $agence = Agence::query()->find($agenceId);
        if ($agence === null || ! $agence->actif || ! $agence->isEnrolledForPointageQr()) {
            return null;
        }

        if (($decoded['exp'] ?? 0) < Carbon::now()->timestamp) {
            $isLongLived = $agence->isVirtual() || ($agence->pointage_qr_type === 'static');
            if (! $isLongLived) {
                return null;
            }
        }

        $version = (int) ($decoded['v'] ?? 1);
        if ($version >= 2 && $user !== null) {
            if (! $this->pointageQr->verifyToken($token, $agence, $user)) {
                return null;
            }
        } elseif ($version >= 2) {
            return null;
        } else {
            if (! $this->verifySiteTokenSignature($token, $agence, $decoded)) {
                return null;
            }
        }

        return $agence;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePointageTokenPayload(string $token): ?array
    {
        $decoded = PointageQrBase64::urlSafeDecode($token);
        if ($decoded === false || ! str_contains($decoded, '|')) {
            return null;
        }

        [$body, $sig] = explode('|', $decoded, 2);

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        $data['_body'] = $body;
        $data['_sig'] = $sig;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function verifySiteTokenSignature(string $token, Agence $agence, array $decoded): bool
    {
        $secret = $this->pointageQr->ensureSecret($agence);
        $body = (string) ($decoded['_body'] ?? '');
        $sig = (string) ($decoded['_sig'] ?? '');
        $expected = hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, $sig);
    }
}
