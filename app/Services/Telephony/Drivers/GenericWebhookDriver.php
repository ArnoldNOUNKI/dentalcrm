<?php

namespace App\Services\Telephony\Drivers;

use App\Enums\CallStatus;
use App\Models\TelephonyAgent;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\PhoneNormalizer;
use App\Services\Telephony\NormalizedCall;
use Filament\Forms;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Webhook générique : tout service (Kavkom, Diabolocom, 3CX, OnOff, Zadarma…) capable d'envoyer
 * un webhook JSON ou formulaire à chaque appel. Les champs sont reconnus automatiquement,
 * ou indiqués par leur chemin (ex. data.call.duration).
 */
class GenericWebhookDriver extends TelephonyDriver
{
    /** Chemins essayés par défaut pour chaque information. */
    private const GUESSES = [
        'external_id' => ['call_id', 'callId', 'id', 'uuid', 'uniqueid', 'linkedid', 'cdr_id', 'data.id', 'data.call_id', 'call.id'],
        'direction' => ['direction', 'type', 'call_type', 'data.direction', 'call.direction'],
        'from' => ['from', 'from_number', 'caller', 'caller_number', 'caller_id', 'src', 'data.from', 'call.from'],
        'to' => ['to', 'to_number', 'callee', 'called_number', 'dst', 'data.to', 'call.to'],
        'external_number' => ['external_number', 'contact_number', 'customer_number', 'raw_digits', 'data.external_number'],
        'status' => ['status', 'call_status', 'disposition', 'state', 'result', 'data.status', 'call.status'],
        'duration' => ['duration', 'talk_duration', 'billsec', 'call_duration', 'incall_duration', 'data.duration', 'call.duration'],
        'started_at' => ['started_at', 'start_time', 'start', 'calldate', 'date', 'timestamp', 'data.started_at', 'call.started_at'],
        'ended_at' => ['ended_at', 'end_time', 'end', 'data.ended_at'],
        'answered' => ['answered', 'is_answered', 'data.answered'],
        'recording_url' => ['recording_url', 'recording', 'record_url', 'record', 'data.recording_url'],
        'voicemail_url' => ['voicemail_url', 'voicemail'],
        'transcript' => ['transcript', 'transcription', 'data.transcript'],
        'agent_id' => ['agent_id', 'user_id', 'user.id', 'agent.id', 'data.user_id'],
        'agent_email' => ['agent_email', 'user_email', 'user.email', 'agent.email'],
        'agent_extension' => ['extension', 'agent_extension', 'user.extension', 'agent.extension'],
        'event' => ['event', 'event_type', 'type_event'],
    ];

    public function key(): string
    {
        return 'generic';
    }

    public function label(): string
    {
        return 'Webhook générique (autre service)';
    }

    public function description(): string
    {
        return 'Pour tout service capable d\'envoyer un webhook à chaque appel (Kavkom, Diabolocom, 3CX, OnOff, Zadarma…).';
    }

    public function supportsSync(): bool
    {
        return false;
    }

    public function credentialFields(): array
    {
        return [
            Forms\Components\Select::make('auth_mode')->label('Vérification des webhooks')->default('none')->live()
                ->options([
                    'none' => 'Aucune (jeton secret de l\'URL uniquement)',
                    'header' => 'Secret dans un en-tête HTTP',
                    'hmac' => 'Signature HMAC-SHA256 du corps',
                ]),
            Forms\Components\TextInput::make('auth_header')->label('Nom de l\'en-tête')->default('X-Webhook-Secret')
                ->visible(fn (Forms\Get $get) => in_array($get('auth_mode'), ['header', 'hmac'], true)),
            Forms\Components\TextInput::make('auth_secret')->label('Secret')->password()->revealable()
                ->visible(fn (Forms\Get $get) => in_array($get('auth_mode'), ['header', 'hmac'], true)),
            Forms\Components\TextInput::make('click_to_call_url')->label('URL de click-to-call (facultatif)')->columnSpanFull()
                ->placeholder('https://pbx.example.fr/api/dial?ext={extension}&number={number}&token=XXX')
                ->helperText('Appelée par le CRM (GET) pour lancer un appel. Variables : {number}, {number_national}, {extension}, {agent_id}, {agent_phone}, {email}.'),
        ];
    }

    public function settingFields(): array
    {
        return [
            Forms\Components\KeyValue::make('mapping')
                ->label('Correspondance des champs (facultatif)')
                ->keyLabel('Information CRM')->valueLabel('Chemin dans le webhook')
                ->helperText('Laissez vide pour la détection automatique. Clés possibles : '.implode(', ', array_keys(self::GUESSES)).'. Exemple : duration → data.call.billsec')
                ->columnSpanFull(),
        ];
    }

    public function webhookInstructions(TelephonyProvider $provider): ?string
    {
        return 'Dans votre service de téléphonie, configurez un webhook (POST, JSON ou formulaire) vers cette URL, déclenché <b>à la fin de chaque appel</b>. Les champs usuels (call_id, direction, from, to, status, duration, started_at, recording_url…) sont reconnus automatiquement.';
    }

    public function supportsClickToCall(TelephonyProvider $provider): bool
    {
        return filled($provider->credential('click_to_call_url'));
    }

    public function testConnection(TelephonyProvider $provider): string
    {
        return 'Rien à tester côté serveur : envoyez un appel de test depuis votre service et vérifiez le journal d\'appels.'
            .($provider->last_webhook_at ? ' Dernier webhook reçu '.$provider->last_webhook_at->diffForHumans().'.' : ' Aucun webhook reçu pour l\'instant.');
    }

    public function verifyWebhook(TelephonyProvider $provider, Request $request): bool
    {
        $secret = (string) $provider->credential('auth_secret');
        $header = (string) $request->header($provider->credential('auth_header', 'X-Webhook-Secret'));

        return match ($provider->credential('auth_mode', 'none')) {
            'header' => $secret !== '' && hash_equals($secret, $header),
            'hmac' => $secret !== '' && (hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $header)
                || hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $header)),
            default => true,
        };
    }

    public function parseWebhook(TelephonyProvider $provider, Request $request): array
    {
        $payload = $request->json()->all() ?: $request->all();
        $get = fn (string $field) => $this->value($provider, $payload, $field);

        $id = $get('external_id');
        if (blank($id)) {
            return [];
        }

        $dir = strtolower((string) $get('direction'));
        $direction = in_array($dir, ['in', 'inbound', 'incoming', 'entrant', 'received'], true) ? 'in' : 'out';
        $duration = (int) $get('duration');
        $answered = filter_var($get('answered'), FILTER_VALIDATE_BOOLEAN) || $duration > 0;
        $statusRaw = $get('status');
        $event = strtolower((string) $get('event'));
        $inProgress = in_array($event, ['ringing', 'call.created', 'created', 'answered', 'call.answered', 'start'], true)
            || CallStatus::guess($statusRaw, $direction) === CallStatus::InProgress;

        $status = $inProgress ? CallStatus::InProgress : CallStatus::guess($statusRaw, $direction, $answered);
        if ($status === CallStatus::Answered && ! $answered && $duration === 0 && $statusRaw === null) {
            $status = $direction === 'in' ? CallStatus::Missed : CallStatus::NoAnswer;
        }
        if (filled($get('voicemail_url')) && $direction === 'in' && $status !== CallStatus::Answered) {
            $status = CallStatus::Voicemail;
        }

        return [new NormalizedCall(
            externalId: (string) $id,
            direction: $direction,
            status: $status,
            fromNumber: $get('from'),
            toNumber: $get('to'),
            externalNumber: $get('external_number'),
            startedAt: NormalizedCall::date($get('started_at')) ?? now()->subSeconds($duration),
            endedAt: NormalizedCall::date($get('ended_at')) ?? ($inProgress ? null : now()),
            duration: $status === CallStatus::Answered ? $duration : 0,
            recordingUrl: $get('recording_url'),
            voicemailUrl: $get('voicemail_url'),
            transcript: is_string($get('transcript')) ? $get('transcript') : null,
            agentExternalId: $get('agent_id') !== null ? (string) $get('agent_id') : null,
            agentEmail: $get('agent_email'),
            agentNumber: $direction === 'in' ? $get('to') : $get('from'),
            agentExtension: $get('agent_extension') !== null ? (string) $get('agent_extension') : null,
            isFinal: ! $inProgress,
            raw: $payload,
        )];
    }

    public function clickToCall(TelephonyProvider $provider, User $user, ?TelephonyAgent $agent, string $number): string
    {
        $e164 = PhoneNormalizer::normalize($number) ?? $number;
        $url = strtr((string) $provider->credential('click_to_call_url'), [
            '{number}' => rawurlencode($e164),
            '{number_national}' => rawurlencode(str_starts_with($e164, '+33') ? '0'.substr($e164, 3) : $e164),
            '{extension}' => rawurlencode((string) $agent?->extension),
            '{agent_id}' => rawurlencode((string) $agent?->external_id),
            '{agent_phone}' => rawurlencode((string) $agent?->phone_number),
            '{email}' => rawurlencode($user->email),
        ]);

        $r = Http::timeout(15)->get($url);
        if ($r->failed()) {
            throw new RuntimeException("Le service a répondu {$r->status()} : ".mb_substr($r->body(), 0, 200));
        }

        return 'Appel lancé.';
    }

    private function value(TelephonyProvider $provider, array $payload, string $field): mixed
    {
        $custom = $provider->setting('mapping.'.$field);
        $paths = $custom ? [$custom] : self::GUESSES[$field];

        foreach ($paths as $path) {
            $v = Arr::get($payload, $path);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        return null;
    }
}
