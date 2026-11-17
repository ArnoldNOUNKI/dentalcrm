<?php

namespace App\Jobs;

use App\Models\TelephonyProvider;
use App\Services\Telephony\CallSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class SyncTelephonyProvider implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $providerId, public ?string $from = null, public ?string $to = null) {}

    public function uniqueId(): string
    {
        return (string) $this->providerId;
    }

    public function handle(CallSynchronizer $sync): void
    {
        $provider = TelephonyProvider::find($this->providerId);

        if ($provider && $provider->is_active) {
            $sync->sync($provider, $this->from ? Carbon::parse($this->from) : null, $this->to ? Carbon::parse($this->to) : null);
        }
    }
}
