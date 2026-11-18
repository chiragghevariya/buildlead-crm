<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Public lead webhook: per client IP.
        RateLimiter::for('ingest', fn (Request $request) => Limit::perMinute(
            (int) config('services.leads.ingest_rate_per_minute', 20)
        )->by('ingest|'.$request->ip()));

        // Authenticated API: per user (falls back to IP).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
