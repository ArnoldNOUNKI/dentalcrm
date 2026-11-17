<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LeadResource;
use App\Models\Task;
use App\Services\PhoneNormalizer;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class TodayTasks extends BaseWidget
{
    protected static ?string $heading = 'Mes rappels du jour';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 2];

    public function table(Table $table): Table
    {
        return $table
            ->query(Task::query()->with('lead')->where('user_id', Auth::id())->pending()->where('due_at', '<=', now()->endOfDay())->orderBy('due_at'))
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Aucun rappel aujourd\'hui')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                Tables\Columns\TextColumn::make('due_at')->label('Heure')->dateTime('H:i')
                    ->color(fn (Task $r) => $r->due_at->isPast() ? 'danger' : null)
                    ->description(fn (Task $r) => $r->due_at->isToday() ? null : $r->due_at->format('d/m')),
                Tables\Columns\TextColumn::make('title')->label('Objet')->wrap(),
                Tables\Columns\TextColumn::make('lead.company_name')->label('Lead')
                    ->url(fn (Task $r) => $r->lead_id ? LeadResource::getUrl('view', ['record' => $r->lead_id]) : null),
                Tables\Columns\TextColumn::make('lead.phone_normalized')->label('Tél.')->formatStateUsing(fn ($state) => PhoneNormalizer::format($state)),
            ])
            ->actions([
                Tables\Actions\Action::make('fait')->label('Fait')->icon('heroicon-o-check')->color('success')->button()->size('xs')
                    ->action(fn (Task $r) => $r->complete()),
            ]);
    }
}
