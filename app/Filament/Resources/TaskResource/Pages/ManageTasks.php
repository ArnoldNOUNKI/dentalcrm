<?php

namespace App\Filament\Resources\TaskResource\Pages;

use App\Filament\Resources\TaskResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageTasks extends ManageRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nouvelle tâche')
                ->mutateFormDataUsing(fn (array $data) => $data + ['created_by' => Auth::id()])
                ->after(function ($record) {
                    if ($lead = $record->lead) {
                        $next = $lead->tasks()->pending()->orderBy('due_at')->first();
                        $lead->forceFill(['next_action_at' => $next?->due_at, 'next_action' => $next?->title])->saveQuietly();
                    }
                }),
        ];
    }
}
