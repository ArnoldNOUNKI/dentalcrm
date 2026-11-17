<?php

namespace App\Console\Commands;

use App\Services\Ringover\RingoverCallIngestor;
use App\Services\Ringover\RingoverClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncRingoverCalls extends Command
{
    protected $signature = 'ringover:sync {--minutes= : Fenêtre de synchronisation en minutes}';

    protected $description = 'Récupère les appels Ringover récents (rattrapage des webhooks perdus)';

    public function handle(RingoverClient $client, RingoverCallIngestor $ingestor): int
    {
        if (! $client->isConfigured()) {
            $this->warn('Ringover non configuré (RINGOVER_API_KEY).');

            return self::SUCCESS;
        }

        $minutes = (int) ($this->option('minutes') ?: config('services.ringover.sync_lookback_minutes', 30));
        $calls = $client->listCalls(now()->subMinutes($minutes), now());

        $count = 0;
        foreach ($calls as $row) {
            $id = (string) ($row['call_id'] ?? $row['cdr_id'] ?? '');
            // On traite uniquement les appels terminés
            if ($id === '' || empty($row['end_time'])) {
                continue;
            }

            Cache::lock('ringover-call-'.$id, 30)->block(20, function () use ($ingestor, $row, &$count) {
                if ($ingestor->ingest($row, 'hangup')) {
                    $count++;
                }
            });
        }

        $this->info("{$count} appel(s) synchronisé(s) sur ".count($calls).' récupéré(s).');

        return self::SUCCESS;
    }
}
