<?php

namespace App\Jobs;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadAssigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Traitement automatique d'un lead fraîchement importé :
 * 1. contrôle qualité (numéro exploitable ?)
 * 2. attribution automatique à un commercial
 * 3. passage en « À appeler »
 * 4. scoring IA (asynchrone, débit limité)
 */
class ProcessImportedLead implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $leadId, public ?int $forceAssigneeId = null, public bool $isNew = true) {}

    public function handle(LeadAssigner $assigner): void
    {
        $lead = Lead::find($this->leadId);

        if (! $lead) {
            return;
        }

        if (! $lead->phone_normalized && ! $lead->mobile_normalized && ! $lead->email) {
            $lead->changeStatus(LeadStatus::Invalide, 'Import : aucun moyen de contact exploitable');

            return;
        }

        if ($this->forceAssigneeId) {
            $assigner->assign($lead, User::find($this->forceAssigneeId));
        } elseif (! $lead->assigned_to) {
            $assigner->assign($lead);
        }

        if ($this->isNew && $lead->status === LeadStatus::Nouveau && $lead->dial_number) {
            $lead->changeStatus(LeadStatus::AAppeler, 'Import : lead prêt à être appelé');
        }

        if (config('services.anthropic.auto_score_imports') && filled(config('services.anthropic.key'))) {
            ScoreLeadWithAi::dispatch($lead->id);
        }
    }
}
