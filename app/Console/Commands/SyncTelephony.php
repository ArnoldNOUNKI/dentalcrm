<?php

namespace App\Console\Commands;

use App\Jobs\SyncTelephonyProvider;
use App\Models\TelephonyProvider;
use App\Services\Telephony\CallSynchronizer;
use Illuminate\Console\Command;

class SyncTelephony extends Command
{
    protected $signature = 'telephony:sync {--provider= : ID d\'un service précis} {--days= : Rattraper l\'historique sur N jours} {--now : Exécuter immédiatement (sans file d\'attente)}';

    protected $description = 'Récupère automatiquement les appels des services de téléphonie connectés';

    public function handle(CallSynchronizer $sync): int
    {
        // Compatibilité : crée le service Ringover depuis les anciennes variables RINGOVER_*
        TelephonyProvider::ringoverFromEnv();

        $providers = TelephonyProvider::query()->active()
            ->when($this->option('provider'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $failed = false;

        foreach ($providers as $provider) {
            $forced = $this->option('provider') || $this->option('days');
            if (! $provider->driver()->supportsSync() || (! $forced && ! $sync->isDue($provider))) {
                continue;
            }

            $from = $this->option('days') ? now()->subDays((int) $this->option('days')) : null;

            if ($this->option('now')) {
                try {
                    $count = $sync->sync($provider, $from);
                    $this->info("{$provider->name} : {$count} appel(s) traité(s).");
                } catch (\Throwable $e) {
                    $failed = true;
                    $this->error("{$provider->name} : {$e->getMessage()}");
                }
            } else {
                SyncTelephonyProvider::dispatch($provider->id, $from?->toIso8601String());
                $this->line("{$provider->name} : synchronisation lancée.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
