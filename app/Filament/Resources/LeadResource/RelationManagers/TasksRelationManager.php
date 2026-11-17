<?php

namespace App\Filament\Resources\LeadResource\RelationManagers;

use App\Filament\Resources\TaskResource;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Rappels';

    protected static ?string $icon = 'heroicon-o-bell-alert';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $n = $ownerRecord->tasks()->pending()->count();

        return $n ? (string) $n : null;
    }

    public function form(Form $form): Form
    {
        return TaskResource::form($form, withLead: false);
    }

    public function table(Table $table): Table
    {
        return TaskResource::table($table)
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Nouveau rappel')
                    ->mutateFormDataUsing(fn (array $data) => $data + ['created_by' => Auth::id(), 'user_id' => $data['user_id'] ?? Auth::id()])
                    ->after(function (Model $record) {
                        $lead = $this->getOwnerRecord();
                        $next = $lead->tasks()->pending()->orderBy('due_at')->first();
                        $lead->forceFill(['next_action_at' => $next?->due_at, 'next_action' => $next?->title])->saveQuietly();
                    }),
            ]);
    }
}
