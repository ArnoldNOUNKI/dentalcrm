<?php

namespace App\Filament\Widgets;

use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Filament\Widgets\Concerns\ScopedByDashboardFilters;
use App\Models\Activity;
use App\Models\Call;
use App\Models\Collection;
use App\Models\Lead;
use App\Models\Task;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class CrmStatsOverview extends BaseWidget
{
    use InteractsWithPageFilters, ScopedByDashboardFilters;

    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $from = $this->periodStart();
        $user = $this->scopedUser();

        $leads = Lead::query()->when($user, fn ($q) => $q->where('assigned_to', $user->id));
        $calls = Call::query()->where('started_at', '>=', $from)->when($user, fn ($q) => $q->where('user_id', $user->id));

        $totalCalls = (clone $calls)->count();
        $answered = (clone $calls)->where('is_answered', true)->count();
        $rate = $totalCalls ? round($answered / $totalCalls * 100) : 0;

        $interested = Activity::query()
            ->where('type', 'status_change')->where('occurred_at', '>=', $from)
            ->whereIn('meta->to', [LeadStatus::Interesse->value, LeadStatus::KitEnvoye->value])
            ->when($user, fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('assigned_to', $user->id)))
            ->distinct('lead_id')->count('lead_id');

        $collections = Collection::query()->when($user, fn ($q) => $q->where('user_id', $user->id));
        $paid = (clone $collections)->where('status', CollectionStatus::Payee->value)->where('paid_at', '>=', $from)->sum('paid_amount');
        $inProgress = (clone $collections)->whereIn('status', array_map(fn ($s) => $s->value, CollectionStatus::inProgress()))->count();
        $pipelineValue = (clone $collections)->whereIn('status', array_map(fn ($s) => $s->value, CollectionStatus::inProgress()))->sum('estimated_value');

        $dueToday = Task::query()->pending()->where('due_at', '<=', now()->endOfDay())
            ->when($user, fn ($q) => $q->where('user_id', $user->id))->count();
        $overdue = Task::query()->overdue()->when($user, fn ($q) => $q->where('user_id', $user->id))->count();

        $callsTrend = collect(range(6, 0))->map(fn ($d) => Call::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('started_at', [today()->subDays($d), today()->subDays($d)->endOfDay()])->count())->all();

        return [
            Stat::make('Leads à appeler', Number::format((clone $leads)->callQueue()->count()))
                ->description(Number::format((clone $leads)->open()->count()).' leads ouverts au total')
                ->descriptionIcon('heroicon-m-phone-arrow-up-right')
                ->color('info'),
            Stat::make('Appels passés', Number::format($totalCalls))
                ->description("{$rate} % décrochés ({$answered})")
                ->descriptionIcon('heroicon-m-phone')
                ->chart($callsTrend)
                ->color('success'),
            Stat::make('Prospects intéressés', Number::format($interested))
                ->description('Passés en « Intéressé » ou « Kit envoyé » sur la période')
                ->descriptionIcon('heroicon-m-hand-thumb-up')
                ->color('warning'),
            Stat::make('Rappels du jour', Number::format($dueToday))
                ->description($overdue ? "{$overdue} en retard" : 'Aucun retard')
                ->descriptionIcon($overdue ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($overdue ? 'danger' : 'gray'),
            Stat::make('Collectes en cours', Number::format($inProgress))
                ->description('Valeur estimée : '.Number::currency($pipelineValue, 'EUR', 'fr'))
                ->descriptionIcon('heroicon-m-cube')
                ->color('primary'),
            Stat::make('Rachats payés', Number::currency($paid, 'EUR', 'fr'))
                ->description('Sur la période sélectionnée')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
