<?php

namespace App\Filament\Pages;

use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Vue Kanban du pipeline commercial : glisser-déposer une carte change le statut du lead (historisé).
 */
class Pipeline extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Prospection';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?string $title = 'Pipeline';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.pipeline';

    public ?int $userFilter = null;

    public string $search = '';

    public bool $showClosed = false;

    private const PER_COLUMN = 50;

    public function getStatusesProperty(): array
    {
        $open = [LeadStatus::Nouveau, LeadStatus::AAppeler, LeadStatus::Injoignable, LeadStatus::ARappeler, LeadStatus::Interesse, LeadStatus::KitEnvoye, LeadStatus::Client];

        return $this->showClosed ? array_merge($open, [LeadStatus::NonInteresse, LeadStatus::Invalide]) : $open;
    }

    /** @return array<string, array{leads: Collection, total: int}> */
    public function getColumnsProperty(): array
    {
        $columns = [];

        foreach ($this->statuses as $status) {
            $query = $this->baseQuery()->where('status', $status->value);
            $columns[$status->value] = [
                'total' => (clone $query)->count(),
                'leads' => $query->with('assignee:id,name')
                    ->orderByRaw('CASE WHEN next_action_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('next_action_at')
                    ->orderByDesc('score')
                    ->limit(self::PER_COLUMN)
                    ->get(['id', 'company_name', 'city', 'score', 'temperature', 'next_action_at', 'next_action', 'assigned_to', 'call_attempts', 'type']),
            ];
        }

        return $columns;
    }

    public function getAgentsProperty(): array
    {
        return Auth::user()->canSeeAllLeads() ? User::salesAgents()->pluck('name', 'id')->all() : [];
    }

    private function baseQuery()
    {
        return Lead::query()->visibleTo()
            ->when($this->userFilter, fn ($q) => $q->where('assigned_to', $this->userFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('company_name', 'like', '%'.$this->search.'%')
                ->orWhere('city', 'like', '%'.$this->search.'%')
                ->orWhere('postal_code', 'like', $this->search.'%')));
    }

    public function moveLead(int $leadId, string $status): void
    {
        $lead = Lead::query()->visibleTo()->find($leadId);
        $target = LeadStatus::tryFrom($status);

        if (! $lead || ! $target || $lead->status === $target) {
            return;
        }

        $lead->changeStatus($target, 'Déplacé dans le pipeline');

        Notification::make()->title($lead->company_name.' → '.$target->getLabel())->success()->send();
    }

    public function leadUrl(int $id): string
    {
        return LeadResource::getUrl('view', ['record' => $id]);
    }
}
