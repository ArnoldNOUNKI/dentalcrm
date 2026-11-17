<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Filament\Resources\LeadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected ?LeadStatus $previousStatus = null;

    protected ?int $previousAssignee = null;

    protected function beforeSave(): void
    {
        $this->previousStatus = $this->record->getOriginal('status') instanceof LeadStatus
            ? $this->record->getOriginal('status')
            : LeadStatus::tryFrom((string) $this->record->getOriginal('status'));
        $this->previousAssignee = $this->record->getOriginal('assigned_to');
    }

    protected function afterSave(): void
    {
        $lead = $this->record;

        if ($this->previousStatus !== $lead->status) {
            $lead->logActivity(ActivityType::StatusChange, sprintf('Statut : %s → %s', $this->previousStatus?->getLabel() ?? '—', $lead->status->getLabel()));
        }

        if ($this->previousAssignee !== $lead->assigned_to && $lead->assignee) {
            $lead->logActivity(ActivityType::Assignment, 'Attribué à '.$lead->assignee->name);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
