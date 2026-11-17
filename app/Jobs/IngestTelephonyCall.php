<?php

namespace App\Jobs;

use App\Models\TelephonyProvider;
use App\Services\Telephony\CallIngestor;
use App\Services\Telephony\NormalizedCall;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestTelephonyCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    /** @param array<string, mixed> $call NormalizedCall::toArray() */
    public function __construct(public int $providerId, public array $call) {}

    public function handle(CallIngestor $ingestor): void
    {
        if ($provider = TelephonyProvider::find($this->providerId)) {
            $ingestor->ingest($provider, NormalizedCall::fromArray($this->call));
        }
    }
}
