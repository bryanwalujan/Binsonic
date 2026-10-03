<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($path = config('app.public_path')) {
            $this->app->usePublicPath($path);
        }
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        RateLimiter::for('device-api', fn (Request $r) => [Limit::perMinute(300)->by($r->ip()), Limit::perMinute(120)->by(hash('sha256', $r->bearerToken() ?? $r->ip()))]);
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(mb_strtolower($r->input('email', '')).'|'.$r->ip()));
    }
}
