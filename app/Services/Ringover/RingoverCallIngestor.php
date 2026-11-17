<?php

namespace App\Services\Ringover;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Jobs\SummarizeCallWithAi;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Transforme un événement Ringover (webhook ou ligne de l'API /calls) en appel CRM :
 * - rattache l'appel au lead (via le numéro du correspondant) et à l'agent (user_id Ringover ou numéro)
 * - journalise dans la timeline, incrémente les tentatives, met à jour « dernier contact »
 * - déclenche l'analyse IA quand l'appel est terminé
 *
 * Le parsing est volontairement tolérant : Ringover a fait évoluer ses payloads
 * (données à plat ou sous « data », noms de champs variables).
 */
class RingoverCallIngestor
{
    private const FINAL_EVENTS = ['hangup', 'missed', 'voicemail', 'call_ended', 'ended', 'aftercall', 'after_call', 'record', 'recording', 'transcription', 'summary'];

    /** @param array<string, mixed> $payload */
    public function ingest(array $payload, ?string $event = null): ?Call
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $event = strtolower((string) ($event ?? $payload['event'] ?? $data['event'] ?? $data['last_state'] ?? 'hangup'));

        $callId = (string) $this->first($data, ['call_id', 'cdr_id', 'id', 'channel_id', 'uuid']);
        if ($callId === '') {
            return null;
        }

        $direction = $this->direction($data);
        $from = PhoneNormalizer::normalize((string) $this->first($data, ['from_number', 'caller_number', 'from']));
        $to = PhoneNormalizer::normalize((string) $this->first($data, ['to_number', 'receiver_number', 'to', 'contact_number']));
        $external = $direction === 'in' ? $from : $to;
        $agentLine = $direction === 'in' ? $to : $from;

        $call = Call::firstOrNew(['ringover_call_id' => $callId]);
        $isNew = ! $call->exists;
        $wasFinal = (bool) $call->ended_at;

        $user = $this->resolveUser($data, $agentLine) ?? $call->user;
        $lead = $call->lead ?? $this->resolveLead($external);

        $answered = $this->bool($this->first($data, ['is_answered', 'answered']))
            || filled($this->first($data, ['answered_time', 'answered_at']))
            || $event === 'answered';

        $duration = (int) ($this->first($data, ['incall_duration', 'duration', 'total_duration', 'call_duration']) ?? $call->duration);

        $call->fill(array_filter([
            'lead_id' => $lead?->id,
            'user_id' => $user?->id,
            'direction' => $direction,
            'from_number' => $from,
            'to_number' => $to,
            'ringover_status' => $event,
            'started_at' => $this->date($this->first($data, ['start_time', 'started_at', 'timestamp'])) ?? $call->started_at ?? now(),
            'duration' => $duration ?: null,
            'recording_url' => $this->first($data, ['record', 'recording', 'record_url', 'recording_url']) ?: null,
            'transcript' => $this->transcript($data) ?: null,
        ], fn ($v) => $v !== null));

        $call->is_answered = $call->is_answered || $answered;

        $isFinal = in_array($event, self::FINAL_EVENTS, true) || filled($this->first($data, ['end_time', 'ended_at', 'hangup_time']));
        if ($isFinal && ! $call->ended_at) {
            $call->ended_at = $this->date($this->first($data, ['end_time', 'ended_at', 'hangup_time'])) ?? now();
        }

        $call->raw_payload = $payload;
        $call->save();

        // Effets sur le lead : une seule fois, à la fin de l'appel
        if ($lead && $isFinal && ! $wasFinal) {
            $this->applyToLead($lead, $call);
        }

        // Analyse IA dès qu'il y a de la matière (transcription Ringover Empower ou notes agent)
        if ($isFinal && $call->lead_id && $call->hasContentForAi() && ! $call->ai_processed_at && config('services.anthropic.auto_summarize_calls')) {
            SummarizeCallWithAi::dispatch($call->id)->delay(now()->addSeconds(5));
        }

        return $call;
    }

    private function applyToLead(Lead $lead, Call $call): void
    {
        $updates = ['last_contacted_at' => $call->started_at ?? now()];

        if ($call->direction === 'out') {
            $updates['call_attempts'] = $lead->call_attempts + 1;
        }

        $lead->forceFill($updates)->saveQuietly();

        $label = match (true) {
            $call->direction === 'in' && ! $call->is_answered => 'Appel entrant manqué',
            $call->direction === 'in' => 'Appel entrant ('.$call->duration_label.')',
            $call->is_answered => 'Appel sortant décroché ('.$call->duration_label.')',
            default => 'Appel sortant sans réponse',
        };

        $lead->logActivity(ActivityType::Call, $label, null, ['call_id' => $call->id, 'ringover_call_id' => $call->ringover_call_id], $call->user_id);

        if ($lead->status === LeadStatus::Nouveau) {
            $lead->changeStatus($call->is_answered ? LeadStatus::AAppeler : LeadStatus::Injoignable);
        } elseif (! $call->is_answered && $call->direction === 'out' && $lead->status === LeadStatus::AAppeler) {
            $lead->changeStatus(LeadStatus::Injoignable);
        }
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

    /** @param array<string, mixed> $data */
    private function resolveUser(array $data, ?string $agentLine): ?User
    {
        $ringoverUserId = $this->first($data, ['user.user_id', 'user.id', 'user_id', 'agent_id']);

        if ($ringoverUserId) {
            $user = User::where('ringover_user_id', (string) $ringoverUserId)->first();
            if ($user) {
                return $user;
            }
        }

        $email = $this->first($data, ['user.email', 'agent_email']);
        if ($email && ($user = User::where('email', $email)->first())) {
            return $user;
        }

        if ($agentLine) {
            return User::whereIn('ringover_number', PhoneNormalizer::variants($agentLine))->first();
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function direction(array $data): string
    {
        $d = strtolower((string) $this->first($data, ['direction', 'type', 'call_direction']));

        return in_array($d, ['in', 'inbound', 'incoming', 'entrant'], true) ? 'in' : 'out';
    }

    /** @param array<string, mixed> $data */
    private function transcript(array $data): ?string
    {
        $t = $this->first($data, ['transcription', 'transcript', 'transcription.text', 'empower.transcription', 'speech_to_text']);

        if (is_array($t)) {
            // Format segmenté : [{speaker, text}, …]
            return collect($t)->map(fn ($seg) => is_array($seg)
                ? trim(($seg['speaker'] ?? $seg['channel'] ?? '').' : '.($seg['text'] ?? $seg['sentence'] ?? ''))
                : (string) $seg)->implode("\n");
        }

        return $t ? (string) $t : null;
    }

    /** @param array<string, mixed> $data */
    private function first(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            $date = is_numeric($value)
                ? Carbon::createFromTimestamp((int) ($value > 9999999999 ? $value / 1000 : $value))
                : Carbon::parse($value);

            // Ringover envoie de l'UTC : on convertit dans le fuseau de l'application avant stockage
            return $date->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function bool(mixed $v): bool
    {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
