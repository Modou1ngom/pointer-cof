<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\VerifyOtpRequest;
use App\Models\Otp;
use App\Models\User;
use App\Services\PointageOtpService;
use App\Support\LoginAttemptGuard;
use App\Support\MobileApiUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly PointageOtpService $otpService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $email = mb_strtolower(trim($validated['email']));

        try {
            LoginAttemptGuard::ensureNotLocked($email, $request->ip());
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Trop de tentatives.';

            return response()->json(['message' => $msg], 429);
        }

        $user = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();

        $passwordOk = Hash::check(
            $validated['password'],
            $user?->password ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        );

        if (! $user || ! $passwordOk || ! $user->is_active) {
            LoginAttemptGuard::hit($email, $request->ip());

            return response()->json(['message' => 'Identifiants incorrects'], 401);
        }

        LoginAttemptGuard::clear($email, $request->ip());

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Otp::query()->where('identifier', $email)->whereNull('used_at')->delete();

        Otp::query()->create([
            'identifier' => $email,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        $mailResult = $this->otpService->sendOtpEmailToAddress((string) $user->email, $code, 'POINTRUST');
        if (! $mailResult['ok']) {
            Log::warning('POINTRUST API login : OTP non envoyé par e-mail', [
                'user_id' => $user->id,
                'message' => $mailResult['message'] ?? null,
            ]);
        }

        $user->tokens()->delete();
        $plainToken = $user->createToken(
            $validated['device_name'],
            ['otp-pending'],
            now()->addMinutes(10),
        )->plainTextToken;

        $response = [
            'token' => $plainToken,
            'requires_otp' => true,
            'requiresOtp' => true,
            'message' => $mailResult['ok']
                ? ($mailResult['via_log_fallback']
                    ? 'Code OTP généré (envoi e-mail en mode journal — configurez SMTP).'
                    : 'Code OTP envoyé')
                : 'Code OTP généré (échec envoi e-mail).',
        ];

        if (($mailResult['via_log_fallback'] ?? false) && app()->environment('local')) {
            $response['otp_delivery'] = 'log';
        }

        if ($this->mayExposeDebugOtp()) {
            $response['debug_otp'] = $code;
        }

        return response()->json($response);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $rawIdentifier = trim($validated['identifier']);
        $identifierKey = str_contains($rawIdentifier, '@')
            ? mb_strtolower($rawIdentifier)
            : $rawIdentifier;

        try {
            LoginAttemptGuard::ensureNotLocked('otp:'.$identifierKey, $request->ip());
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Trop de tentatives.';

            return response()->json(['message' => $msg], 429);
        }

        $otp = Otp::query()
            ->where('identifier', $identifierKey)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if ($otp === null || ! $otp->matchesPlainCode($validated['code'])) {
            LoginAttemptGuard::hit('otp:'.$identifierKey, $request->ip());

            return response()->json(['message' => 'Code invalide ou expiré'], 422);
        }

        $otp->forceFill(['used_at' => now()])->save();
        LoginAttemptGuard::clear('otp:'.$identifierKey, $request->ip());

        $user = str_contains($identifierKey, '@')
            ? User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$identifierKey])->first()
            : User::query()->where('matricule', $identifierKey)->first();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Code invalide ou expiré'], 422);
        }

        $user->tokens()->delete();
        $ttl = max(30, (int) config('security.api_token_ttl_minutes', 720));
        $plain = $user->createToken(
            'mobile',
            ['*'],
            now()->addMinutes($ttl),
        )->plainTextToken;

        return response()->json([
            'access_token' => $plain,
            'token_type' => 'Bearer',
            'expires_in' => $ttl * 60,
            'requires_device_registration' => true,
            'requiresDeviceRegistration' => true,
            'user' => MobileApiUserResource::toArray($user, $request),
        ]);
    }

    private function mayExposeDebugOtp(): bool
    {
        return app()->environment('local')
            && (bool) config('app.debug')
            && (bool) config('pointrust.debug_otp_in_login_response');
    }
}
