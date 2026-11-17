<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Filament\Widgets\Concerns\ScopedByDashboardFilters;
use App\Models\Lead;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class LeadsByStatusChart extends ChartWidget
{
    use InteractsWithPageFilters, ScopedByDashboardFilters;

    protected static ?string $heading = 'Pipeline des leads';

    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $user = $this->scopedUser();

        $counts = Lead::query()
            ->when($user, fn ($q) => $q->where('assigned_to', $user->id))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $palette = [
            'nouveau' => '#94a3b8', 'a_appeler' => '#3b82f6', 'injoignable' => '#f59e0b', 'a_rappeler' => '#fbbf24',
            'interesse' => '#22c55e', 'kit_envoye' => '#d97706', 'client' => '#15803d', 'non_interesse' => '#ef4444', 'invalide' => '#64748b',
        ];

        $statuses = collect(LeadStatus::cases())->filter(fn ($s) => ($counts[$s->value] ?? 0) > 0)->values();

        return [
            'datasets' => [[
                'data' => $statuses->map(fn ($s) => (int) $counts[$s->value])->all(),
                'backgroundColor' => $statuses->map(fn ($s) => $palette[$s->value])->all(),
                'borderWidth' => 0,
            ]],
            'labels' => $statuses->map(fn ($s) => $s->getLabel())->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'right']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'cutout' => '60%',
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
