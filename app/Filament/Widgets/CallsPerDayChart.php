<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ScopedByDashboardFilters;
use App\Models\Call;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class CallsPerDayChart extends ChartWidget
{
    use InteractsWithPageFilters, ScopedByDashboardFilters;

    protected static ?string $heading = 'Activité téléphonique';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = ['md' => 1, 'xl' => 2];

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $user = $this->scopedUser();
        $days = max(7, min(90, (int) ($this->filters['period'] ?? 30)));
        $start = today()->subDays($days - 1);

        $rows = Call::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->where('started_at', '>=', $start)
            ->get(['started_at', 'is_answered'])
            ->groupBy(fn (Call $c) => $c->started_at->toDateString());

        $labels = $total = $answered = [];
        for ($d = $start->copy(); $d->lte(today()); $d->addDay()) {
            $key = $d->toDateString();
            $labels[] = $d->format('d/m');
            $total[] = isset($rows[$key]) ? $rows[$key]->count() : 0;
            $answered[] = isset($rows[$key]) ? $rows[$key]->where('is_answered', true)->count() : 0;
        }

        return [
            'datasets' => [
                ['label' => 'Appels', 'data' => $total, 'borderColor' => '#d97706', 'backgroundColor' => 'rgba(217,119,6,0.12)', 'fill' => true, 'tension' => 0.3],
                ['label' => 'Décrochés', 'data' => $answered, 'borderColor' => '#16a34a', 'backgroundColor' => 'transparent', 'tension' => 0.3],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
