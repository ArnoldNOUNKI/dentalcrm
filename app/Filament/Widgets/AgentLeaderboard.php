<?php

namespace App\Filament\Widgets;

use App\Enums\CollectionStatus;
use App\Filament\Widgets\Concerns\ScopedByDashboardFilters;
use App\Models\Collection;
use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class AgentLeaderboard extends BaseWidget
{
    use InteractsWithPageFilters, ScopedByDashboardFilters;

    protected static ?string $heading = 'Performance de l\'équipe';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->canManageTeam() ?? false;
    }

    public function table(Table $table): Table
    {
        $from = $this->periodStart();

        return $table
            ->query(
                User::query()->salesAgents()
                    ->withCount([
                        'calls as calls_total' => fn ($q) => $q->where('started_at', '>=', $from),
                        'calls as calls_answered' => fn ($q) => $q->where('started_at', '>=', $from)->where('is_answered', true),
                        'calls as calls_interested' => fn ($q) => $q->where('started_at', '>=', $from)->whereIn('outcome', ['interesse', 'kit_demande']),
                        'leads as leads_open' => fn ($q) => $q->open(),
                    ])
                    ->withSum(['calls as talk_time' => fn ($q) => $q->where('started_at', '>=', $from)], 'duration')
                    ->addSelect(['paid_total' => Collection::query()
                        ->selectRaw('COALESCE(SUM(paid_amount),0)')
                        ->whereColumn('collections.user_id', 'users.id')
                        ->where('status', CollectionStatus::Payee->value)
                        ->where('paid_at', '>=', $from)])
            )
            ->defaultSort('calls_total', 'desc')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Commercial')->weight('semibold'),
                Tables\Columns\TextColumn::make('calls_total')->label('Appels')->sortable()->alignCenter(),
                Tables\Columns\TextColumn::make('calls_answered')->label('Décrochés')->sortable()->alignCenter()
                    ->description(fn ($record) => $record->calls_total ? round($record->calls_answered / $record->calls_total * 100).' %' : null),
                Tables\Columns\TextColumn::make('talk_time')->label('Temps de com.')->alignCenter()
                    ->formatStateUsing(fn ($state) => gmdate('G\hi', (int) $state)),
                Tables\Columns\TextColumn::make('calls_interested')->label('Intéressés')->sortable()->alignCenter()->color('success'),
                Tables\Columns\TextColumn::make('leads_open')->label('Leads ouverts')->alignCenter(),
                Tables\Columns\TextColumn::make('paid_total')->label('Rachats payés')->money('EUR', locale: 'fr')->sortable()->alignEnd(),
            ]);
    }
}
