<?php

namespace App\Providers;

use App\Models\Agence;
use App\Support\PointageRhSettingsMerger;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $locale = (string) config('app.locale', 'fr');
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);
        CarbonPeriod::setLocale($locale);
        CarbonInterval::setLocale($locale);

        setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'french', 'fra');

        Route::model('site', Agence::class);
        PointageRhSettingsMerger::mergeStoredPayloadIntoConfig();

        Password::defaults(function () {
            if ($this->app->environment('production')) {
                return Password::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised();
            }

            return Password::min(8);
        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
            // Empêche les fuites de stack / secrets même si .env est mal configuré.
            config(['app.debug' => false]);
            config(['pointrust.debug_otp_in_login_response' => false]);
            config(['pointage.otp_email_fallback_log' => false]);
            config(['pointage.dev_unlock_code' => null]);

            $jwtSecret = (string) env('POINTRUST_JWT_SECRET', '');
            if (strlen($jwtSecret) < 32) {
                throw new \RuntimeException(
                    'POINTRUST_JWT_SECRET (min. 32 caractères) est obligatoire en production.'
                );
            }
        }
    }
}
