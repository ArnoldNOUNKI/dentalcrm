<?php

namespace App\Providers;

use App\Models\Lead;
use App\Observers\LeadObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Lead::observe(LeadObserver::class);

        // Débit max d'appels IA en tâche de fond (scoring des imports)
        RateLimiter::for('ai', fn () => Limit::perMinute((int) env('AI_JOBS_PER_MINUTE', 40)));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
