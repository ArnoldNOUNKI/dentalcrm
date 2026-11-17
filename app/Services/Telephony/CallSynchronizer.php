<?php

namespace App\Services\Telephony;

use App\Models\TelephonyProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Récupère automatiquement les appels d'un fournisseur sur une période (par fenêtres),
 * avec un recouvrement pour ne rien rater ; l'ingestion est idempotente.
 */
class CallSynchronizer
{
    /** Recouvrement : on relit toujours les dernières minutes (appels terminés tardivement). */
    public const OVERLAP_MINUTES = 30;

    public function __construct(private readonly CallIngestor $ingestor) {}

    /** @return int nombre d'appels traités */
    public function sync(TelephonyProvider $provider, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $driver = $provider->driver();
        $to ??= now();
        $from ??= ($provider->synced_until ?? now()->subDay())->copy()->subMinutes(self::OVERLAP_MINUTES);

        $count = 0;

        try {
            $windowStart = $from->copy();
            while ($windowStart->lt($to)) {
                $windowEnd = $windowStart->copy()->addDays($driver->maxSyncWindowDays())->min($to);

                foreach ($driver->fetchCalls($provider, $windowStart, $windowEnd) as $call) {
                    $this->ingestor->ingest($provider, $call);
                    $count++;
                }

                $windowStart = $windowEnd;
            }

            $provider->forceFill([
                'last_synced_at' => now(),
                'synced_until' => $to->max($provider->synced_until ?? $to),
                'last_sync_status' => 'ok',
                'last_sync_count' => $count,
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $provider->forceFill([
                'last_synced_at' => now(),
                'last_sync_status' => 'error',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::warning("Synchronisation téléphonie « {$provider->name} » en échec", ['error' => $e->getMessage()]);

            throw $e;
        }

        return $count;
    }

    public function isDue(TelephonyProvider $provider): bool
    {
        return $provider->is_active
            && $provider->sync_enabled
            && $provider->driver()->supportsSync()
            && (! $provider->last_synced_at || $provider->last_synced_at->lte(now()->subMinutes(max(1, $provider->sync_interval_minutes))->addSeconds(10)));
    }
}
