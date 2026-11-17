<?php

namespace App\Console\Commands;

use App\Filament\Resources\LeadResource;
use App\Models\Task;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class NotifyDueTasks extends Command
{
    protected $signature = 'crm:notify-due-tasks';

    protected $description = 'Envoie une notification aux commerciaux pour les rappels arrivant à échéance';

    public function handle(): int
    {
        $tasks = Task::query()
            ->with(['user', 'lead'])
            ->pending()
            ->whereNull('notified_at')
            ->where('due_at', '<=', now()->addMinutes(10))
            ->limit(500)
            ->get();

        foreach ($tasks as $task) {
            if (! $task->user) {
                continue;
            }

            $notification = Notification::make()
                ->title('Rappel : '.$task->title)
                ->body(($task->lead?->company_name ? $task->lead->company_name.' — ' : '').'prévu à '.$task->due_at->format('H:i'))
                ->icon('heroicon-o-bell-alert')
                ->warning();

            if ($task->lead) {
                $notification->actions([
                    Action::make('ouvrir')->label('Ouvrir la fiche')->url(LeadResource::getUrl('view', ['record' => $task->lead])),
                ]);
            }

            $notification->sendToDatabase($task->user);
            $task->forceFill(['notified_at' => now()])->save();
        }

        $this->info($tasks->count().' notification(s) envoyée(s).');

        return self::SUCCESS;
    }
}
