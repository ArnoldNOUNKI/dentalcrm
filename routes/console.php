<?php

use Illuminate\Support\Facades\Schedule;

// Rattrapage des appels Ringover (si un webhook a été perdu)
Schedule::command('ringover:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->when(fn () => config('services.ringover.sync_enabled') && filled(config('services.ringover.api_key')));

// Notifications des rappels qui arrivent à échéance
Schedule::command('crm:notify-due-tasks')->everyFiveMinutes()->withoutOverlapping();

// Nettoyage
Schedule::command('queue:prune-batches --hours=72')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
