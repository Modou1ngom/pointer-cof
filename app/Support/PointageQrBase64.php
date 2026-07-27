<?php

namespace App\Support;

/**
 * Décodage Base64 URL-safe (jetons QR pointage sans padding « = »).
 */
final class PointageQrBase64
{
    public static function urlSafeDecode(string $token): string|false
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $b64 = strtr($token, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($b64, true);
    }
}
