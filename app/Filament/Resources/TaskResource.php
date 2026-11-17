<?php

namespace App\Filament\Resources;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Filament\Resources\TaskResource\Pages;
use App\Models\Task;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Prospection';

    protected static ?string $modelLabel = 'rappel';

    protected static ?string $navigationLabel = 'Rappels & tâches';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['lead', 'user']);

        return Auth::user()?->canManageTeam() ? $query : $query->where('user_id', Auth::id());
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Task::where('user_id', Auth::id())->pending()->where('due_at', '<=', now()->endOfDay())->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Task::where('user_id', Auth::id())->overdue()->exists() ? 'danger' : 'warning';
    }

    public static function form(Form $form, bool $withLead = true): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('Objet')->required()->columnSpanFull(),
            Forms\Components\Select::make('lead_id')->label('Lead')->relationship('lead', 'company_name', fn (Builder $query) => $query->visibleTo())
                ->searchable()->preload(false)->visible($withLead),
            Forms\Components\Select::make('user_id')->label('Assigné à')
                ->options(fn () => User::where('is_active', true)->pluck('name', 'id'))
                ->default(fn () => Auth::id())->required()
                ->disabled(fn () => ! Auth::user()->canManageTeam())->dehydrated(),
            Forms\Components\DateTimePicker::make('due_at')->label('Échéance')->seconds(false)->native(false)->required()
                ->default(now()->addDay()->setTime(10, 0)),
            Forms\Components\Select::make('type')->options(TaskType::class)->default(TaskType::Rappel->value)->required(),
            Forms\Components\Select::make('priority')->label('Priorité')->options(TaskPriority::class)->default(TaskPriority::Normale->value)->required(),
            Forms\Components\Textarea::make('description')->rows(3)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_at')
            ->modifyQueryUsing(fn (Builder $query) => $query->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END'))
            ->columns([
                Tables\Columns\IconColumn::make('completed_at')->label('')->boolean()
                    ->state(fn (Task $r) => (bool) $r->completed_at)
                    ->trueIcon('heroicon-o-check-circle')->falseIcon('heroicon-o-ellipsis-horizontal-circle')->width('1%'),
                Tables\Columns\TextColumn::make('due_at')->label('Échéance')->dateTime('d/m H:i')->sortable()
                    ->color(fn (Task $r) => ! $r->completed_at && $r->due_at->isPast() ? 'danger' : null)
                    ->description(fn (Task $r) => $r->completed_at ? 'fait '.$r->completed_at->diffForHumans() : $r->due_at->diffForHumans()),
                Tables\Columns\TextColumn::make('title')->label('Objet')->searchable()->wrap()
                    ->icon(fn (Task $r) => $r->type?->getIcon()),
                Tables\Columns\TextColumn::make('lead.company_name')->label('Lead')->searchable()->placeholder('—')
                    ->url(fn (Task $r) => $r->lead_id ? LeadResource::getUrl('view', ['record' => $r->lead_id]) : null),
                Tables\Columns\TextColumn::make('lead.phone_normalized')->label('Téléphone')
                    ->formatStateUsing(fn ($state) => PhoneNormalizer::format($state))->toggleable(),
                Tables\Columns\TextColumn::make('priority')->label('Priorité')->badge(),
                Tables\Columns\TextColumn::make('user.name')->label('Pour')->visible(fn () => Auth::user()->canManageTeam()),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('done')->label('État')
                    ->placeholder('À faire')->trueLabel('Faits')->falseLabel('Tous')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('completed_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('completed_at'),
                    ),
                Tables\Filters\Filter::make('retard')->label('En retard')->toggle()->query(fn (Builder $query) => $query->overdue()),
                Tables\Filters\Filter::make('aujourdhui')->label('Aujourd\'hui')->toggle()
                    ->query(fn (Builder $query) => $query->whereBetween('due_at', [today(), today()->endOfDay()])),
                Tables\Filters\SelectFilter::make('type')->options(TaskType::class),
                Tables\Filters\SelectFilter::make('user_id')->label('Commercial')->relationship('user', 'name')
                    ->visible(fn () => Auth::user()->canManageTeam()),
            ])
            ->actions([
                Tables\Actions\Action::make('fait')->label('Fait')->icon('heroicon-o-check')->color('success')->button()
                    ->visible(fn (Task $r) => ! $r->completed_at)
                    ->action(function (Task $r) {
                        $r->complete();
                        Notification::make()->title('Tâche terminée')->success()->send();
                    }),
                Tables\Actions\Action::make('reporter')->label('Reporter')->icon('heroicon-o-forward')->color('gray')->iconButton()
                    ->visible(fn (Task $r) => ! $r->completed_at)
                    ->form([Forms\Components\DateTimePicker::make('due_at')->label('Nouvelle échéance')->seconds(false)->native(false)->required()
                        ->default(now()->addDay()->setTime(10, 0))])
                    ->action(function (Task $r, array $data) {
                        $r->update(['due_at' => $data['due_at'], 'notified_at' => null]);
                        if ($r->lead && $r->lead->next_action === $r->title) {
                            $r->lead->forceFill(['next_action_at' => $data['due_at']])->saveQuietly();
                        }
                    }),
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('faits')->label('Marquer comme faits')->icon('heroicon-o-check')
                    ->action(fn (EloquentCollection $records) => $records->each->complete())
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTasks::route('/'),
        ];
    }
}
