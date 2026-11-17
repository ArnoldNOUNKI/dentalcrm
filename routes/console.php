<?php

use Illuminate\Support\Facades\Schedule;

// Récupération automatique des appels de tous les services de téléphonie connectés
// (chaque service a son propre intervalle, réglable dans Téléphonie → Intégrations)
Schedule::command('telephony:sync')->everyMinute()->withoutOverlapping();

// Notifications des rappels qui arrivent à échéance
Schedule::command('crm:notify-due-tasks')->everyFiveMinutes()->withoutOverlapping();

// Nettoyage
Schedule::command('queue:prune-batches --hours=72')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
