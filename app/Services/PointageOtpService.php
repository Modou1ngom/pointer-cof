<?php

namespace App\Services;

use App\Mail\PointageOtpMail;
use App\Models\Agence;
use App\Models\User;
use App\Support\PointagePhone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PointageOtpService
{
    public const OTP_TTL_SECONDS = 600;

    public const SESSION_TTL_SECONDS = 900;

    private function otpCacheKey(int $userId): string
    {
        return 'pointage_otp_v1:'.$userId;
    }

    private function virtualPunchOtpCacheKey(int $userId): string
    {
        return 'pointage_virtual_otp_v1:'.$userId;
    }

    private function sessionCacheKey(string $token): string
    {
        return 'pointage_otp_sess_v1:'.$token;
    }

    public function qrFingerprint(string $qrToken): string
    {
        return hash('sha256', $qrToken);
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function sendOtp(User $user, string $qrToken, Agence $agence, PointageQrService $qrService): array
    {
        if (! $qrService->verifyToken($qrToken, $agence, $user)) {
            return ['ok' => false, 'message' => 'QR Code invalide ou expiré (non lié à votre compte, e-mail ou téléphone).'];
        }

        if (! ($agence->pointage_qr_enabled ?? true)) {
            return ['ok' => false, 'message' => 'Le QR Code de cette agence est désactivé.'];
        }

        $rateKey = 'pointage-otp-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return ['ok' => false, 'message' => 'Trop de demandes de code. Réessayez dans une minute.'];
        }
        RateLimiter::hit($rateKey, 60);

        $user->profilCollaborateurAssocie();
        $profil = $user->profil;
        if (! $profil) {
            return ['ok' => false, 'message' => 'Profil collaborateur introuvable.'];
        }

        /** Destinataire OTP : fiche RH (profil) en priorité, puis compte utilisateur. */
        $email = strtolower(trim((string) ($profil->email ?: $user->email)));
        if ($email === '') {
            return ['ok' => false, 'message' => 'E-mail introuvable sur votre fiche collaborateur. Contactez les RH.'];
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Adresse e-mail invalide sur votre compte. Contactez les RH.'];
        }

        $tel = trim((string) $profil->telephone);
        $telDigits = preg_replace('/\D/', '', $tel) ?? '';
        if ($telDigits === '' || strlen($telDigits) < 9) {
            return ['ok' => false, 'message' => 'Numéro de téléphone professionnel incomplet sur votre profil. Contactez les RH.'];
        }

        $code = (string) random_int(100000, 999999);
        $fp = $this->qrFingerprint($qrToken);

        Cache::put($this->otpCacheKey($user->id), [
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'qr_fp' => $fp,
            'agence_id' => $agence->id,
        ], self::OTP_TTL_SECONDS);

        $mailResult = $this->sendOtpEmailToAddress($email, $code, $agence->nom);
        if (! $mailResult['ok']) {
            Cache::forget($this->otpCacheKey($user->id));

            return ['ok' => false, 'message' => $mailResult['message'] ?? 'Envoi de l’e-mail impossible. Réessayez plus tard ou contactez le support.'];
        }
        $emailViaLogFallback = $mailResult['via_log_fallback'];

        $this->dispatchSms($tel, $code, $agence->nom);

        $msg = 'Un code à 6 chiffres a été envoyé sur votre e-mail et par SMS.';
        if ($emailViaLogFallback) {
            $msg .= ' (Le serveur SMTP n’a pas pu envoyer l’e-mail : le message a été écrit dans storage/logs/laravel.log — récupérez le code dans ce fichier ou par SMS.)';
        }
        if (! config('pointage.otp_sms_enabled', true) || config('pointage.otp_sms_driver', 'log') === 'log') {
            $msg .= ' (SMS en mode journalisation : vérifiez storage/logs/laravel.log ou configurez POINTAGE_OTP_SMS_DRIVER=twilio.)';
        }

        return ['ok' => true, 'message' => $msg];
    }

    /**
     * Envoi OTP par e-mail (API mobile POINTRUST ou pointage web).
     *
     * @return array{ok: bool, via_log_fallback: bool, message?: string}
     */
    public function sendOtpEmailToAddress(string $email, string $code, string $siteNom = 'POINTRUST'): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'via_log_fallback' => false, 'message' => 'Adresse e-mail invalide.'];
        }

        try {
            $this->sendOtpMailable($email, $code, $siteNom);

            return ['ok' => true, 'via_log_fallback' => false];
        } catch (\Throwable $e) {
            Log::error('Pointage OTP e-mail : échec du mailer principal', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'to' => $email,
                'mail_default' => config('mail.default'),
                'otp_mail_mailer' => config('pointage.otp_mail_mailer'),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

            $canFallback = (bool) config('pointage.otp_email_fallback_log', false);
            $otpMailer = config('pointage.otp_mail_mailer');
            $alreadyLog = is_string($otpMailer) && $otpMailer === 'log';

            if ($canFallback && ! $alreadyLog && config('mail.default') !== 'log') {
                try {
                    Mail::mailer('log')->to($email)->send(new PointageOtpMail($code, $siteNom));
                    Log::warning('Pointage OTP e-mail : secours via mailer « log » (code visible dans storage/logs/laravel.log).');

                    return ['ok' => true, 'via_log_fallback' => true];
                } catch (\Throwable $e2) {
                    Log::error('Pointage OTP e-mail : secours log en échec', ['message' => $e2->getMessage()]);

                    return ['ok' => false, 'via_log_fallback' => false, 'message' => 'Envoi de l’e-mail impossible. Réessayez plus tard ou contactez le support.'];
                }
            }

            return ['ok' => false, 'via_log_fallback' => false, 'message' => 'Envoi de l’e-mail impossible. Vérifiez la configuration MAIL_* (SMTP / Mailtrap).'];
        }
    }

    private function sendOtpMailable(string $email, string $code, string $siteNom): void
    {
        $mailable = new PointageOtpMail($code, $siteNom);
        $mailer = config('pointage.otp_mail_mailer');

        if (is_string($mailer) && $mailer !== '') {
            Mail::mailer($mailer)->to($email)->send($mailable);

            return;
        }

        Mail::to($email)->send($mailable);
    }

    private function dispatchSms(string $telephoneE164OrLocal, string $code, string $siteNom): void
    {
        $body = "COFINA Pointage : code {$code} ({$siteNom}). Valide 10 min. Ne partagez pas ce code.";
        $driver = config('pointage.otp_sms_driver', 'log');

        if ($driver === 'log' || ! config('pointage.otp_sms_enabled', true)) {
            Log::info('[Pointage OTP SMS — simulation / log]', [
                'to' => $telephoneE164OrLocal,
                'message' => $body,
            ]);

            return;
        }

        if ($driver === 'twilio') {
            $this->dispatchSmsTwilio($telephoneE164OrLocal, $body);

            return;
        }

        Log::warning('Pointage OTP SMS : driver inconnu', ['driver' => $driver, 'to' => $telephoneE164OrLocal]);
    }

    /**
     * @return array<string, mixed>
     */
    private function twilioHttpVerifyOptions(): array
    {
        $v = config('pointage.otp_sms_http_verify');

        if ($v === false || $v === 'false' || $v === '0') {
            Log::warning('Pointage OTP Twilio : SSL verify désactivé (POINTAGE_OTP_SMS_HTTP_VERIFY=false). À éviter en production.');

            return ['verify' => false];
        }

        if (is_string($v) && $v !== '' && $v !== 'true' && $v !== '1') {
            if (is_file($v)) {
                return ['verify' => $v];
            }
            Log::warning('Pointage OTP Twilio : fichier CA introuvable pour SSL', ['path' => $v]);
        }

        return [];
    }

    private function dispatchSmsTwilio(string $telephoneRaw, string $messageBody): void
    {
        $sid = (string) config('pointage.otp_sms_twilio_account_sid', '');
        $token = (string) config('pointage.otp_sms_twilio_auth_token', '');
        $from = (string) config('pointage.otp_sms_twilio_from', '');
        $messagingServiceSid = (string) config('pointage.otp_sms_twilio_messaging_service_sid', '');

        if ($sid === '' || $token === '') {
            Log::error('Pointage OTP SMS Twilio : TWILIO_ACCOUNT_SID ou TWILIO_AUTH_TOKEN manquant.');

            return;
        }

        if ($messagingServiceSid === '' && $from === '') {
            Log::error('Pointage OTP SMS Twilio : renseignez TWILIO_MESSAGING_SERVICE_SID ou TWILIO_SMS_FROM.');

            return;
        }

        $to = PointagePhone::toE164($telephoneRaw);
        if ($to === null) {
            Log::error('Pointage OTP SMS Twilio : numéro non convertible en E.164.', ['raw' => $telephoneRaw]);

            return;
        }

        $timeout = (int) config('pointage.otp_sms_http_timeout', 15);
        $url = 'https://api.twilio.com/2010-04-01/Accounts/'.rawurlencode($sid).'/Messages.json';

        $form = [
            'To' => $to,
            'Body' => $messageBody,
        ];
        if ($messagingServiceSid !== '') {
            $form['MessagingServiceSid'] = $messagingServiceSid;
        } else {
            $form['From'] = $from;
        }

        try {
            $response = Http::withOptions($this->twilioHttpVerifyOptions())
                ->withBasicAuth($sid, $token)
                ->asForm()
                ->timeout($timeout)
                ->connectTimeout(min(10, $timeout))
                ->post($url, $form);
        } catch (\Throwable $e) {
            Log::error('Pointage OTP SMS Twilio : échec réseau — '.$e->getMessage(), ['to' => $to]);

            return;
        }

        if (! $response->successful()) {
            Log::error('Pointage OTP SMS Twilio : API erreur', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $decoded = $response->json();
        Log::info('Pointage OTP SMS Twilio : accepté par l’API', [
            'to' => $to,
            'sid' => $decoded['sid'] ?? null,
            'status' => $decoded['status'] ?? null,
        ]);
    }

    /**
     * @return array{ok: bool, message?: string, otp_session_token?: string}
     */
    public function verifyOtp(User $user, string $qrToken, string $code, Agence $agence, PointageQrService $qrService): array
    {
        if (! $qrService->verifyToken($qrToken, $agence, $user)) {
            return ['ok' => false, 'message' => 'QR Code invalide ou expiré.'];
        }

        if (! ($agence->pointage_qr_enabled ?? true)) {
            return ['ok' => false, 'message' => 'Le QR Code de cette agence est désactivé.'];
        }

        $code = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return ['ok' => false, 'message' => 'Saisissez le code à 6 chiffres reçu par e-mail et SMS.'];
        }

        $row = Cache::get($this->otpCacheKey($user->id));
        if (! is_array($row) || empty($row['hash'])) {
            return ['ok' => false, 'message' => 'Aucun code actif. Demandez un nouveau code.'];
        }

        // Ne pas exiger que l’empreinte du QR soit identique à celle du moment de l’envoi : après
        // router.post (OTP), Inertia recharge souvent la page et un QR dynamique nouveau est minté,
        // alors que le cache OTP référence encore l’ancien jeton. Le QR courant est déjà validé
        // ci-dessus (signature, site, liaison employé) ; l’agence du cache est vérifiée juste après.

        if ((int) ($row['agence_id'] ?? 0) !== $agence->id) {
            return ['ok' => false, 'message' => 'Site de pointage incohérent.'];
        }

        if (! password_verify($code, $row['hash'])) {
            return ['ok' => false, 'message' => 'Code incorrect.'];
        }

        Cache::forget($this->otpCacheKey($user->id));

        $sessionToken = Str::random(64);
        Cache::put($this->sessionCacheKey($sessionToken), [
            'user_id' => $user->id,
            'qr_fp' => $this->qrFingerprint($qrToken),
            'agence_id' => $agence->id,
        ], self::SESSION_TTL_SECONDS);

        return ['ok' => true, 'otp_session_token' => $sessionToken, 'message' => 'Code validé. Poursuivez avec la position GPS puis la biométrie.'];
    }

    public function validateOtpSession(?string $token, User $user, string $qrToken, Agence $agence): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $row = Cache::get($this->sessionCacheKey($token));
        if (! is_array($row) || (int) ($row['user_id'] ?? 0) !== $user->id) {
            return false;
        }

        if (($row['qr_fp'] ?? '') !== $this->qrFingerprint($qrToken)) {
            return false;
        }

        return (int) ($row['agence_id'] ?? 0) === $agence->id;
    }

    public function revokeOtpSession(string $token): void
    {
        Cache::forget($this->sessionCacheKey($token));
    }

    /**
     * OTP e-mail pour pointage sur agence virtuelle (sans SMS / sans jeton QR v2).
     *
     * @return array{ok: bool, message?: string, via_log_fallback?: bool}
     */
    public function sendVirtualPunchOtp(User $user, Agence $agence, string $qrToken, string $email): array
    {
        if (! $agence->isVirtual()) {
            return ['ok' => false, 'message' => 'Ce site n’est pas une agence virtuelle.'];
        }

        if (! ($agence->pointage_qr_enabled ?? true)) {
            return ['ok' => false, 'message' => 'Le QR Code de cette agence est désactivé.'];
        }

        $email = mb_strtolower(trim($email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Adresse e-mail invalide.'];
        }

        $rateKey = 'pointage-virtual-otp-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            return ['ok' => false, 'message' => 'Trop de demandes de code. Réessayez dans une minute.'];
        }
        RateLimiter::hit($rateKey, 60);

        $code = (string) random_int(100000, 999999);

        Cache::put($this->virtualPunchOtpCacheKey($user->id), [
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'qr_fp' => $this->qrFingerprint($qrToken),
            'agence_id' => $agence->id,
            'email' => $email,
        ], self::OTP_TTL_SECONDS);

        $mailResult = $this->sendOtpEmailToAddress($email, $code, $agence->nom);
        if (! $mailResult['ok']) {
            Cache::forget($this->virtualPunchOtpCacheKey($user->id));

            return ['ok' => false, 'message' => $mailResult['message'] ?? 'Envoi de l’e-mail impossible. Réessayez plus tard.'];
        }

        $msg = 'Un code à 6 chiffres a été envoyé sur '.$email.'.';
        if ($mailResult['via_log_fallback'] ?? false) {
            $msg .= ' (E-mail journalisé dans storage/logs — configurez SMTP.)';
        }

        return [
            'ok' => true,
            'message' => $msg,
            'via_log_fallback' => (bool) ($mailResult['via_log_fallback'] ?? false),
        ];
    }

    /**
     * Vérifie et consomme l’OTP virtuel (une seule utilisation).
     *
     * @return array{ok: bool, message?: string}
     */
    public function consumeVirtualPunchOtp(User $user, Agence $agence, string $qrToken, string $code, string $email): array
    {
        if (! $agence->isVirtual()) {
            return ['ok' => false, 'message' => 'Ce site n’est pas une agence virtuelle.'];
        }

        $code = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (strlen($code) !== 6 || ! ctype_digit($code)) {
            return ['ok' => false, 'message' => 'Saisissez le code à 6 chiffres reçu par e-mail.'];
        }

        $email = mb_strtolower(trim($email));
        $row = Cache::get($this->virtualPunchOtpCacheKey($user->id));
        if (! is_array($row) || empty($row['hash'])) {
            return ['ok' => false, 'message' => 'Aucun code actif. Demandez un nouveau code.'];
        }

        if ((int) ($row['agence_id'] ?? 0) !== $agence->id) {
            return ['ok' => false, 'message' => 'Site de pointage incohérent.'];
        }

        if (($row['email'] ?? '') !== $email) {
            return ['ok' => false, 'message' => 'E-mail incohérent avec la demande de code.'];
        }

        if (($row['qr_fp'] ?? '') !== $this->qrFingerprint($qrToken)) {
            return ['ok' => false, 'message' => 'QR Code incohérent avec la demande de code. Reprenez le scan.'];
        }

        if (! password_verify($code, $row['hash'])) {
            return ['ok' => false, 'message' => 'Code incorrect.'];
        }

        Cache::forget($this->virtualPunchOtpCacheKey($user->id));

        return ['ok' => true, 'message' => 'Code validé.'];
    }
}
