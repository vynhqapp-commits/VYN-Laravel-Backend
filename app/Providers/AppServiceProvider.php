<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('public', function (Request $request) {
            // Per-IP budget for public browsing/availability/booking.
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('otp', function (Request $request) {
            // OTP should be stricter to reduce abuse; keyed by IP + identifier (email/phone).
            $identifier = (string) ($request->input('email') ?? $request->input('identifier') ?? '');
            return Limit::perMinute(6)->by($request->ip() . '|' . strtolower(trim($identifier)));
        });
    }
}
