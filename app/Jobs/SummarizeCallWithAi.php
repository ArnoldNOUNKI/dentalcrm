<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\Ai\LeadIntelligence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SummarizeCallWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $callId, public bool $force = false) {}

    public function handle(LeadIntelligence $ai): void
    {
        $call = Call::with('lead')->find($this->callId);

        if (! $call || ! $ai->isAvailable() || ! $call->hasContentForAi()) {
            return;
        }

        if ($call->ai_processed_at && ! $this->force) {
            return;
        }

        $ai->summarizeCall($call);
    }
}
