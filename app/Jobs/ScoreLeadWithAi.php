<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Ai\LeadIntelligence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

class ScoreLeadWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [20, 60, 180];

    public function __construct(public int $leadId) {}

    /** Limite le débit d'appels à l'API (cf. RateLimiter « ai » dans AppServiceProvider). */
    public function middleware(): array
    {
        return [new RateLimited('ai')];
    }

    public function handle(LeadIntelligence $ai): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead || ! $ai->isAvailable()) {
            return;
        }

        $ai->scoreLead($lead);
    }
}
