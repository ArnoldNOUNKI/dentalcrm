<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CallOutcome;
use App\Enums\TaskType;
use App\Jobs\SummarizeCallWithAi;
use App\Models\Call;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Qualification d'un appel par le commercial (fiche lead ou file d'appels).
 * Si un appel Ringover récent non qualifié existe pour ce lead, il est complété plutôt que dupliqué.
 */
class CallLogger
{
    public function log(
        Lead $lead,
        CallOutcome $outcome,
        ?string $notes = null,
        ?Carbon $followUpAt = null,
        bool $runAi = true,
        float $durationMinutes = 0,
    ): Call {
        $call = $lead->calls()->whereNotNull('ringover_call_id')->whereNull('outcome')
            ->where('started_at', '>=', now()->subHours(3))->first();

        if ($call) {
            $call->update([
                'outcome' => $outcome,
                'agent_notes' => $notes,
                'user_id' => $call->user_id ?? Auth::id(),
            ]);
        } else {
            $call = $lead->calls()->create([
                'user_id' => Auth::id(),
                'direction' => 'out',
                'to_number' => $lead->dial_number,
                'is_answered' => ! in_array($outcome, [CallOutcome::PasDeReponse, CallOutcome::Messagerie, CallOutcome::MauvaisNumero], true),
                'duration' => (int) round($durationMinutes * 60),
                'started_at' => now(),
                'ended_at' => now(),
                'outcome' => $outcome,
                'agent_notes' => $notes,
                'ringover_status' => 'manual',
            ]);
            $lead->forceFill(['call_attempts' => $lead->call_attempts + 1])->saveQuietly();
        }

        $lead->forceFill(['last_contacted_at' => now()])->saveQuietly();
        $lead->logActivity(ActivityType::Call, 'Appel qualifié : '.$outcome->getLabel(), $notes, ['call_id' => $call->id]);

        if ($next = $outcome->nextLeadStatus()) {
            $lead->changeStatus($next);
        }

        if ($followUpAt) {
            $lead->scheduleFollowUp($followUpAt, 'Rappel suite appel ('.$outcome->getLabel().')');
        } elseif (in_array($outcome, [CallOutcome::PasDeReponse, CallOutcome::Messagerie], true)) {
            // Nouvelle tentative automatique : J+1 à J+3 selon le nombre de tentatives, à une heure différente
            $hour = [10, 14, 17, 12][$lead->call_attempts % 4];
            $lead->forceFill([
                'next_action_at' => now()->addWeekdays(min(3, max(1, $lead->call_attempts)))->setTime($hour, 0),
                'next_action' => 'Nouvelle tentative d\'appel',
            ])->saveQuietly();
        } elseif (in_array($outcome, [CallOutcome::PasInteresse, CallOutcome::MauvaisNumero], true)) {
            $lead->forceFill(['next_action_at' => null, 'next_action' => null])->saveQuietly();
            $lead->tasks()->pending()->update(['completed_at' => now()]);
        }

        if ($outcome === CallOutcome::KitDemande) {
            $lead->scheduleFollowUp(now()->addHour(), 'Envoyer le kit de collecte', type: TaskType::Collecte);
        }

        if ($runAi && $call->fresh()->hasContentForAi() && filled(config('services.anthropic.key'))) {
            SummarizeCallWithAi::dispatch($call->id, force: true);
        }

        return $call;
    }
}
