<?php

namespace App\Services\Telephony\Drivers;

use App\Enums\CallStatus;
use App\Models\TelephonyAgent;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\Telephony\NormalizedCall;
use Filament\Forms;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ringover — API v2 (clé API dans l'en-tête Authorization) + webhooks d'appels.
 */
class RingoverDriver extends TelephonyDriver
{
    private const FINAL_EVENTS = ['hangup', 'missed', 'voicemail', 'call_ended', 'ended', 'aftercall', 'after_call', 'record', 'recording', 'transcription', 'summary'];

    public function key(): string
    {
        return 'ringover';
    }

    public function label(): string
    {
        return 'Ringover';
    }

    public function description(): string
    {
        return 'Clé API : Dashboard Ringover → Développeur → API (droits sur les appels et le click-to-call).';
    }

    public function credentialFields(): array
    {
        return [
            Forms\Components\TextInput::make('api_key')->label('Clé API')->password()->revealable()->required(),
            Forms\Components\Select::make('region')->label('Région du compte')->default('eu')->required()
                ->options(['eu' => 'Europe (public-api.ringover.com)', 'us' => 'États-Unis (public-api-us.ringover.com)']),
        ];
    }

    public function webhookInstructions(TelephonyProvider $provider): ?string
    {
        return 'Dashboard Ringover → <b>Développeur → Webhooks</b> : collez cette URL et cochez les événements d\'appels (sonnerie, décroché, raccroché, manqué, messagerie) ainsi que les enregistrements et transcriptions si votre offre les inclut.';
    }

    public function supportsClickToCall(TelephonyProvider $provider): bool
    {
        return filled($provider->credential('api_key'));
    }

    public function testConnection(TelephonyProvider $provider): string
    {
        $r = $this->http($provider)->get('/calls', ['limit_count' => 1]);

        if (! in_array($r->status(), [200, 204], true)) {
            throw new RuntimeException("Ringover a répondu {$r->status()} : ".($r->json('message') ?? $r->body()));
        }

        return 'Connexion Ringover réussie.';
    }

    public function fetchCalls(TelephonyProvider $provider, Carbon $from, Carbon $to): iterable
    {
        $offset = 0;
        $limit = 500;

        do {
            $r = $this->http($provider)->get('/calls', [
                'start_date' => $from->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'end_date' => $to->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'limit_count' => $limit,
                'limit_offset' => $offset,
            ]);

            if ($r->status() === 204) {
                return;
            }

            if ($r->failed()) {
                throw new RuntimeException("Ringover /calls : HTTP {$r->status()} ".($r->json('message') ?? ''));
            }

            $page = $r->json('call_list') ?? [];
            foreach ($page as $row) {
                if ($call = $this->normalize($row, null)) {
                    yield $call;
                }
            }
            $offset += count($page);
        } while (count($page) === $limit && $offset < 20000);
    }

    public function parseWebhook(TelephonyProvider $provider, Request $request): array
    {
        $payload = $request->json()->all() ?: $request->all();
        if (! $payload) {
            return [];
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $event = strtolower((string) ($request->query('event') ?? $payload['event'] ?? $data['event'] ?? ''));
        $call = $this->normalize($data, $event, $payload);

        return $call ? [$call] : [];
    }

    public function clickToCall(TelephonyProvider $provider, User $user, ?TelephonyAgent $agent, string $number): string
    {
        $from = $agent?->phone_number ?: $user->ringover_number;
        if (! $from) {
            throw new RuntimeException('Renseignez le numéro Ringover de l\'agent (Intégrations → Agents).');
        }

        $r = $this->http($provider)->post('/callback', [
            'from_number' => (int) preg_replace('/\D/', '', $from),
            'to_number' => (int) preg_replace('/\D/', '', $number),
            'timeout' => 30,
            'device' => 'ALL',
            'clir' => false,
        ]);

        if ($r->failed()) {
            throw new RuntimeException('Ringover : '.($r->json('message') ?? $r->body()));
        }

        return 'Votre poste Ringover sonne : décrochez pour joindre le prospect.';
    }

    /** Ligne de l'API /calls ou contenu d'un webhook → appel normalisé. */
    public function normalize(array $d, ?string $event, array $raw = []): ?NormalizedCall
    {
        $id = (string) $this->first($d, ['call_id', 'cdr_id', 'id', 'channel_id', 'uuid']);
        if ($id === '') {
            return null;
        }

        $dirRaw = strtolower((string) $this->first($d, ['direction', 'type', 'call_direction']));
        $direction = in_array($dirRaw, ['in', 'inbound', 'incoming'], true) ? 'in' : 'out';

        $from = (string) $this->first($d, ['from_number', 'caller_number', 'from']);
        $to = (string) $this->first($d, ['to_number', 'receiver_number', 'to']);
        $contact = $this->first($d, ['contact_number']);

        $answeredAt = NormalizedCall::date($this->first($d, ['answered_time', 'answered_at']));
        $endedAt = NormalizedCall::date($this->first($d, ['end_time', 'ended_at', 'hangup_time']));
        $startedAt = NormalizedCall::date($this->first($d, ['start_time', 'started_at', 'timestamp'])) ?? $answeredAt;

        $isAnswered = filter_var($this->first($d, ['is_answered', 'answered']), FILTER_VALIDATE_BOOLEAN) || $answeredAt !== null || $event === 'answered';
        $state = strtolower((string) ($this->first($d, ['last_state', 'state', 'status']) ?? ''));
        $isAnswered = $isAnswered || $state === 'answered';
        $voicemail = $this->first($d, ['voicemail', 'voicemail_url']);

        $status = match (true) {
            $event === 'voicemail' || $state === 'voicemail' || filled($voicemail) => CallStatus::Voicemail,
            $isAnswered => CallStatus::Answered,
            in_array($event, ['ringing', 'answered', 'dialed'], true) && ! $endedAt => CallStatus::InProgress,
            default => CallStatus::guess($state ?: ($event ?: 'missed'), $direction),
        };

        $talk = (int) ($this->first($d, ['incall_duration', 'duration', 'call_duration']) ?? 0);
        $total = (int) ($this->first($d, ['total_duration']) ?? 0);

        $isFinal = $endedAt !== null || in_array($event, self::FINAL_EVENTS, true) || ($event === null && $state !== '');

        return new NormalizedCall(
            externalId: $id,
            direction: $direction,
            status: $isFinal ? $status : CallStatus::InProgress,
            fromNumber: $from ?: null,
            toNumber: $to ?: null,
            externalNumber: $contact ?: null,
            startedAt: $startedAt ?? now(),
            answeredAt: $answeredAt,
            endedAt: $endedAt,
            duration: $status === CallStatus::Answered ? $talk : 0,
            ringDuration: $total > 0 ? max(0, $total - $talk) : ((int) ($this->first($d, ['queue_duration', 'ringing_duration']) ?? 0) ?: null),
            recordingUrl: $this->first($d, ['record', 'recording', 'record_url', 'recording_url']) ?: null,
            voicemailUrl: $voicemail ?: null,
            transcript: $this->transcript($d),
            hangupBy: $this->first($d, ['hangup_by']) ? (string) $this->first($d, ['hangup_by']) : null,
            agentExternalId: ($u = $this->first($d, ['user.user_id', 'user.id', 'user_id', 'agent_id'])) ? (string) $u : null,
            agentEmail: $this->first($d, ['user.email', 'agent_email']),
            agentNumber: $direction === 'in' ? ($to ?: null) : ($from ?: null),
            isFinal: $isFinal,
            raw: $raw ?: $d,
        );
    }

    private function transcript(array $d): ?string
    {
        $t = $this->first($d, ['transcription', 'transcript', 'transcription.text', 'empower.transcription', 'speech_to_text']);

        if (is_array($t)) {
            return collect($t)->map(fn ($seg) => is_array($seg)
                ? trim(($seg['speaker'] ?? $seg['channel'] ?? '').' : '.($seg['text'] ?? $seg['sentence'] ?? ''))
                : (string) $seg)->implode("\n");
        }

        return $t ? (string) $t : null;
    }

    private function first(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            $v = Arr::get($data, $key);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    private function http(TelephonyProvider $provider): PendingRequest
    {
        $key = $provider->credential('api_key');
        if (! $key) {
            throw new RuntimeException('Clé API Ringover manquante.');
        }

        $base = $provider->credential('region') === 'us' ? 'https://public-api-us.ringover.com/v2' : 'https://public-api.ringover.com/v2';

        return Http::baseUrl($base)->withHeaders(['Authorization' => $key])->acceptJson()->timeout(30)->retry(2, 1500, throw: false);
    }
}
