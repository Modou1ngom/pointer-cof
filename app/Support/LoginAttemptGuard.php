<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class LoginAttemptGuard
{
    public static function key(string $email, ?string $ip): string
    {
        $email = mb_strtolower(trim($email));
        $ip = (string) ($ip ?? '0.0.0.0');

        return 'login-lock:'.sha1($email.'|'.$ip);
    }

    public static function ensureNotLocked(string $email, ?string $ip): void
    {
        $key = self::key($email, $ip);
        $max = max(1, (int) config('security.login_max_attempts', 5));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.max(1, (int) ceil($seconds / 60)).' min.',
            ]);
        }
    }

    public static function hit(string $email, ?string $ip): void
    {
        $decay = max(1, (int) config('security.login_decay_minutes', 15)) * 60;
        RateLimiter::hit(self::key($email, $ip), $decay);
    }

    public static function clear(string $email, ?string $ip): void
    {
        RateLimiter::clear(self::key($email, $ip));
    }

    public static function remaining(string $email, ?string $ip): int
    {
        $max = max(1, (int) config('security.login_max_attempts', 5));

        return RateLimiter::retriesLeft(self::key($email, $ip), $max);
    }
}
