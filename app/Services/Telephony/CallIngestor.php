<?php

namespace App\Services\Telephony;

use App\Enums\ActivityType;
use App\Enums\CallStatus;
use App\Enums\LeadStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Filament\Resources\LeadResource;
use App\Jobs\SummarizeCallWithAi;
use App\Models\Call;
use App\Models\Lead;
use App\Models\TelephonyAgent;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Enregistre un appel normalisé (quel que soit le fournisseur) dans le CRM :
 *  - crée ou met à jour l'appel (idempotent : fournisseur + identifiant externe)
 *  - le rattache au lead (numéro du correspondant) et à l'agent (ID, email, ligne, poste)
 *  - à la fin de l'appel, une seule fois : historique, tentatives, statut du lead,
 *    rappel automatique pour un appel entrant manqué, analyse IA
 */
class CallIngestor
{
    public function ingest(TelephonyProvider $provider, NormalizedCall $n): Call
    {
        return Cache::lock("telephony-call-{$provider->id}-{$n->externalId}", 30)
            ->block(20, fn () => $this->store($provider, $n));
    }

    private function store(TelephonyProvider $provider, NormalizedCall $n): Call
    {
        $call = Call::firstOrNew(['telephony_provider_id' => $provider->id, 'external_id' => $n->externalId]);
        $wasFinal = $call->exists && $call->ended_at !== null && $call->status !== null && $call->status !== CallStatus::InProgress;

        $user = $this->resolveUser($provider, $n) ?? $call->user;
        $lead = $call->lead ?? $this->resolveLead($n->externalNumber);

        // Un appel terminé ne repasse jamais « en cours » (événements reçus dans le désordre)
        $status = ($wasFinal && $n->status === CallStatus::InProgress) ? $call->status : $n->status;

        $call->fill(array_filter([
            'lead_id' => $lead?->id,
            'user_id' => $user?->id,
            'direction' => $n->direction,
            'from_number' => $n->fromNumber,
            'to_number' => $n->toNumber,
            'external_number' => $n->externalNumber,
            'status' => $status,
            'ringover_status' => $provider->driver === 'ringover' ? ($n->raw['event'] ?? null) : null,
            'missed_reason' => $n->missedReason,
            'started_at' => $call->started_at ?? $n->startedAt ?? now(),
            'answered_at' => $n->answeredAt,
            'duration' => $n->duration ?: null,
            'ring_duration' => $n->ringDuration,
            'recording_url' => $n->recordingUrl,
            'voicemail_url' => $n->voicemailUrl,
            'transcript' => $n->transcript,
            'hangup_by' => $n->hangupBy,
            'agent_external_id' => $n->agentExternalId ?? $n->agentExtension,
        ], fn ($v) => $v !== null && $v !== ''));

        $call->is_answered = $call->is_answered || $status === CallStatus::Answered;
        if ($n->isFinal && ! $call->ended_at) {
            $call->ended_at = $n->endedAt ?? now();
        }
        $call->raw_payload = $n->raw;
        $call->save();

        $isFinalNow = $n->isFinal && $status !== CallStatus::InProgress;

        if ($isFinalNow && ! $wasFinal) {
            if ($lead) {
                $this->applyToLead($lead, $call, $status);
            }

            if (in_array($status, [CallStatus::Missed, CallStatus::Voicemail], true) && $call->direction === 'in') {
                $this->handleMissedInbound($call, $lead, $user);
            }
        }

        if ($isFinalNow && $call->lead_id && $call->hasContentForAi() && ! $call->ai_processed_at
            && config('services.anthropic.auto_summarize_calls') && filled(config('services.anthropic.key'))) {
            SummarizeCallWithAi::dispatch($call->id)->delay(now()->addSeconds(5));
        }

        return $call;
    }

    private function applyToLead(Lead $lead, Call $call, CallStatus $status): void
    {
        $updates = ['last_contacted_at' => $call->started_at ?? now()];
        if ($call->direction === 'out') {
            $updates['call_attempts'] = $lead->call_attempts + 1;
        }
        $lead->forceFill($updates)->saveQuietly();

        $label = ($call->direction === 'in' ? 'Appel entrant' : 'Appel sortant').' : '.mb_strtolower($status->getLabel())
            .($status === CallStatus::Answered ? ' ('.$call->duration_label.')' : '');

        $lead->logActivity(ActivityType::Call, $label, $call->missed_reason ? 'Motif : '.$call->missed_reason : null,
            ['call_id' => $call->id, 'provider' => $call->telephony_provider_id, 'status' => $status->value], $call->user_id);

        if ($lead->status === LeadStatus::Nouveau) {
            $lead->changeStatus($status === CallStatus::Answered ? LeadStatus::AAppeler : LeadStatus::Injoignable);
        } elseif ($call->direction === 'out' && $status !== CallStatus::Answered && $lead->status === LeadStatus::AAppeler) {
            $lead->changeStatus(LeadStatus::Injoignable);
        } elseif ($call->direction === 'in' && $status === CallStatus::Answered && $lead->status === LeadStatus::Injoignable) {
            $lead->changeStatus(LeadStatus::AAppeler, 'Le prospect a rappelé');
        }
    }

    /** Appel entrant manqué : rappel automatique + notification au commercial. */
    private function handleMissedInbound(Call $call, ?Lead $lead, ?User $agent): void
    {
        $owner = $lead?->assignee ?? $agent;
        if (! $owner) {
            return;
        }

        $who = $lead?->company_name ?? PhoneNormalizer::format($call->external_number) ?? 'Numéro inconnu';
        $title = 'Rappeler : appel '.($call->status === CallStatus::Voicemail ? 'avec message' : 'manqué');

        if ($lead) {
            $lead->tasks()->create([
                'user_id' => $owner->id,
                'title' => $title,
                'type' => TaskType::Rappel,
                'priority' => TaskPriority::Haute,
                'due_at' => now(),
                'description' => 'Appel entrant du '.PhoneNormalizer::format($call->external_number).' le '.$call->started_at?->format('d/m H:i'),
            ]);
            $lead->forceFill(['next_action_at' => now(), 'next_action' => $title])->saveQuietly();
        }

        $notification = Notification::make()
            ->title($title)
            ->body($who.' — '.$call->started_at?->format('d/m H:i'))
            ->icon('heroicon-o-phone-x-mark')
            ->danger();

        if ($lead) {
            $notification->actions([NotificationAction::make('voir')->label('Ouvrir la fiche')->url(LeadResource::getUrl('view', ['record' => $lead]))]);
        }

        $notification->sendToDatabase($owner);
    }

    private function resolveLead(?string $number): ?Lead
    {
        if (! $number) {
            return null;
        }

        $variants = PhoneNormalizer::variants($number);

        return Lead::query()
            ->where(fn ($q) => $q->whereIn('phone_normalized', $variants)->orWhereIn('mobile_normalized', $variants))
            ->latest('updated_at')
            ->first();
    }

    private function resolveUser(TelephonyProvider $provider, NormalizedCall $n): ?User
    {
        if ($n->agentUserId && ($u = User::find($n->agentUserId))) {
            return $u;
        }

        $agents = TelephonyAgent::query()->with('user')->where('telephony_provider_id', $provider->id);

        if ($n->agentExternalId && ($a = (clone $agents)->where('external_id', $n->agentExternalId)->first())) {
            return $a->user;
        }

        if ($n->agentExtension && ($a = (clone $agents)->where('extension', $n->agentExtension)->first())) {
            return $a->user;
        }

        if ($n->agentEmail && ($u = User::where('email', mb_strtolower($n->agentEmail))->first())) {
            return $u;
        }

        if ($n->agentNumber) {
            $variants = PhoneNormalizer::variants($n->agentNumber);
            if ($a = (clone $agents)->whereIn('phone_number', $variants)->first()) {
                return $a->user;
            }
        }

        // Compatibilité : champs Ringover de la fiche utilisateur
        if ($provider->driver === 'ringover') {
            if ($n->agentExternalId && ($u = User::where('ringover_user_id', $n->agentExternalId)->first())) {
                return $u;
            }
            if ($n->agentNumber && ($u = User::whereIn('ringover_number', PhoneNormalizer::variants($n->agentNumber))->first())) {
                return $u;
            }
        }

        return null;
    }
}
