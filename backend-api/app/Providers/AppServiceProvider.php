<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('auth-login', function (Request $request): array {
            $username = $request->input('username');
            $username = is_string($username) ? Str::lower(trim($username)) : '';

            return [
                Limit::perMinute(30)->by('ip:'.$request->ip()),
                Limit::perMinute(5)->by('user:'.hash('sha256', $username).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request): Limit {
            return Limit::perHour(3)->by($request->ip());
        });
    }
}
