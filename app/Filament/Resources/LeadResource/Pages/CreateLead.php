<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\ActivityType;
use App\Filament\Resources\LeadResource;
use App\Jobs\ScoreLeadWithAi;
use App\Services\LeadAssigner;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;

    protected function afterCreate(): void
    {
        $lead = $this->record;
        $lead->logActivity(ActivityType::Note, 'Lead créé manuellement');

        if (! $lead->assigned_to) {
            app(LeadAssigner::class)->assign($lead);
        }

        if (filled(config('services.anthropic.key'))) {
            ScoreLeadWithAi::dispatch($lead->id);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
