<?php

namespace App\Providers;

use App\Contracts\CitizenRegistry;
use App\Contracts\IssueAnalyzer;
use App\Services\Ai\LocalAnalyzer;
use App\Services\DatabaseCitizenRegistry;
use App\Services\NationalIdentity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IssueAnalyzer::class, LocalAnalyzer::class);
        $this->app->bind(CitizenRegistry::class, DatabaseCitizenRegistry::class);
    }

    public function boot(): void
    {
        RateLimiter::for('identity-registration', function (Request $request): array {
            $value = $request->input('national_id');
            $identity = new NationalIdentity;
            $identifier = is_string($value) ? $identity->normalize($value) : '';

            return [
                Limit::perMinute(5)->by('identity-ip:'.$request->ip()),
                Limit::perHour(20)->by('identity-hour:'.$request->ip()),
                Limit::perMinutes(10, 3)->by('identity-id:'.$identity->fingerprint('registration:'.$identifier)),
            ];
        });
        RateLimiter::for('authentication', function (Request $request): array {
            $identity = new NationalIdentity;
            $nationalId = $request->input('national_id');
            $email = $request->input('email');
            $identifier = is_string($nationalId) ? $identity->normalize($nationalId)
                : (is_string($email) ? mb_strtolower(trim($email)) : 'session:'.$request->session()->getId());
            $fingerprint = $identity->fingerprint('authentication:'.$identifier);

            return [
                Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-identity:'.$fingerprint.'|'.$request->ip()),
                Limit::perMinute(10)->by('auth-account:'.$fingerprint),
            ];
        });
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(5)->by('mfa:'.$request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('submission', fn (Request $request) => [
            Limit::perMinute(3)->by('submit-user:'.$request->user()?->id),
            Limit::perHour(12)->by('submit-day:'.$request->user()?->id),
            Limit::perMinute(10)->by('submit-ip:'.$request->ip()),
        ]);
        RateLimiter::for('appeals', fn (Request $request) => Limit::perHour(5)->by('appeal:'.$request->user()?->id));
        RateLimiter::for('reads', fn (Request $request) => Limit::perMinute(120)->by('read:'.$request->ip()));
    }
}
