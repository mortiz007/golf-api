<?php

namespace App\Providers;

use App\Support\Telemetry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Operational telemetry is emitted through the dedicated `stdout` JSON
        // Lines channel, keeping it separate from the default file log.
        $this->app->singleton(Telemetry::class, static fn (): Telemetry => new Telemetry(Log::channel('stdout')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per-user daily cap on listing creation, stricter than the global
        // 60 req/min throttle, to protect the API and control LLM cost
        // (DESIGN §VI). Exceeding it yields the normative RATE_LIMITED 429.
        RateLimiter::for('listing-creation', function (Request $request): Limit {
            return Limit::perDay((int) config('listings.daily_creation_limit', 10))
                ->by((string) ($request->user()?->id ?? $request->ip()));
        });
    }
}
