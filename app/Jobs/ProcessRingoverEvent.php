<?php

namespace App\Jobs;

use App\Services\Ringover\RingoverCallIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ProcessRingoverEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload, public ?string $event = null) {}

    public function handle(RingoverCallIngestor $ingestor): void
    {
        $data = is_array($this->payload['data'] ?? null) ? $this->payload['data'] : $this->payload;
        $callId = (string) (Arr::get($data, 'call_id') ?? Arr::get($data, 'cdr_id') ?? Arr::get($data, 'id') ?? Arr::get($data, 'channel_id') ?? 'unknown');

        // Les événements d'un même appel (answered, hangup, record…) peuvent arriver en même temps
        Cache::lock('ringover-call-'.$callId, 30)->block(20, fn () => $ingestor->ingest($this->payload, $this->event));
    }
}
