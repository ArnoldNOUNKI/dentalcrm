<?php

namespace App\Services\Telephony\Drivers;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\TelephonyAgent;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\Telephony\NormalizedCall;
use Filament\Forms;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Aircall — API REST v1 (Basic Auth api_id:api_token) + webhooks (jeton dans le corps).
 * Un appel manqué = status « done » avec un missed_call_reason renseigné.
 */
class AircallDriver extends TelephonyDriver
{
    private const BASE = 'https://api.aircall.io/v1';

    public function key(): string
    {
        return 'aircall';
    }

    public function label(): string
    {
        return 'Aircall';
    }

    public function description(): string
    {
        return 'API ID et API Token : Dashboard Aircall → Intégrations & API → API Keys.';
    }

    public function credentialFields(): array
    {
        return [
            Forms\Components\TextInput::make('api_id')->label('API ID')->required(),
            Forms\Components\TextInput::make('api_token')->label('API Token')->password()->revealable()->required(),
            Forms\Components\TextInput::make('webhook_token')->label('Jeton du webhook Aircall')->password()->revealable()
                ->helperText('Affiché par Aircall à la création du webhook. Sert à vérifier que les événements viennent bien d\'Aircall.'),
        ];
    }

    public function webhookInstructions(TelephonyProvider $provider): ?string
    {
        return 'Dashboard Aircall → <b>Intégrations & API → Webhooks</b> : créez un webhook avec cette URL et cochez au minimum <code>call.created</code>, <code>call.answered</code>, <code>call.ended</code> et <code>call.voicemail_left</code>. Copiez ensuite le <b>jeton</b> affiché par Aircall dans le champ « Jeton du webhook Aircall ».';
    }

    public function supportsClickToCall(TelephonyProvider $provider): bool
    {
        return filled($provider->credential('api_id')) && filled($provider->credential('api_token'));
    }

    public function testConnection(TelephonyProvider $provider): string
    {
        $r = $this->http($provider)->get('/ping');

        if ($r->failed()) {
            throw new RuntimeException("Aircall a répondu {$r->status()} : ".($r->json('message') ?? $r->json('error') ?? $r->body()));
        }

        return 'Connexion Aircall réussie.';
    }

    public function fetchCalls(TelephonyProvider $provider, Carbon $from, Carbon $to): iterable
    {
        $page = 1;

        do {
            $r = $this->http($provider)->get('/calls', [
                'from' => $from->getTimestamp(),
                'to' => $to->getTimestamp(),
                'order' => 'asc',
                'per_page' => 50,
                'page' => $page,
            ]);

            if ($r->failed()) {
                throw new RuntimeException("Aircall /calls : HTTP {$r->status()} ".($r->json('message') ?? ''));
            }

            foreach ($r->json('calls') ?? [] as $row) {
                yield $this->normalize($row);
            }

            $page++;
        } while ($r->json('meta.next_page_link') && $page <= 200);
    }

    public function verifyWebhook(TelephonyProvider $provider, Request $request): bool
    {
        $expected = (string) $provider->credential('webhook_token');

        // Sans jeton configuré, seul le secret de l'URL protège le webhook
        return $expected === '' || hash_equals($expected, (string) $request->input('token'));
    }

    public function parseWebhook(TelephonyProvider $provider, Request $request): array
    {
        if ($request->input('resource') !== 'call' || ! is_array($request->input('data'))) {
            return [];
        }

        return [$this->normalize($request->input('data'), (string) $request->input('event'))];
    }

    public function clickToCall(TelephonyProvider $provider, User $user, ?TelephonyAgent $agent, string $number): string
    {
        if (! $agent?->external_id) {
            throw new RuntimeException('Renseignez l\'ID utilisateur Aircall de l\'agent (Intégrations → Agents).');
        }

        // Compose le numéro dans l'application Aircall de l'agent
        $r = $this->http($provider)->post('/users/'.urlencode($agent->external_id).'/dial', ['to' => $number]);

        if ($r->failed()) {
            throw new RuntimeException('Aircall : '.($r->json('message') ?? $r->json('troubleshoot') ?? $r->body()));
        }

        return 'Le numéro est composé dans votre application Aircall.';
    }

    public function recordingSource(TelephonyProvider $provider, Call $call, bool $voicemail = false): ?array
    {
        // Les liens Aircall expirent : on redemande un lien frais
        if ($call->external_id) {
            $r = $this->http($provider)->get('/calls/'.urlencode($call->external_id));
            if ($r->successful()) {
                $url = $voicemail ? $r->json('call.voicemail') : $r->json('call.recording');
                if ($url) {
                    return ['url' => $url];
                }
            }
        }

        return parent::recordingSource($provider, $call, $voicemail);
    }

    public function normalize(array $c, ?string $event = null): NormalizedCall
    {
        $direction = ($c['direction'] ?? '') === 'inbound' ? 'in' : 'out';
        $external = $c['raw_digits'] ?? null;
        $line = $c['number']['digits'] ?? null;

        $startedAt = NormalizedCall::date($c['started_at'] ?? null);
        $answeredAt = NormalizedCall::date($c['answered_at'] ?? null);
        $endedAt = NormalizedCall::date($c['ended_at'] ?? null);
        $done = ($c['status'] ?? null) === 'done' || $event === 'call.ended' || $endedAt !== null;

        $missedReason = $c['missed_call_reason'] ?? null;
        $voicemail = $c['voicemail'] ?? null;

        $status = match (true) {
            ! $done => CallStatus::InProgress,
            filled($voicemail) => CallStatus::Voicemail,
            filled($missedReason) || ! $answeredAt => $direction === 'in' ? CallStatus::Missed : CallStatus::NoAnswer,
            default => CallStatus::Answered,
        };

        $talk = ($answeredAt && $endedAt) ? max(0, $endedAt->getTimestamp() - $answeredAt->getTimestamp()) : 0;
        $ring = ($startedAt && ($answeredAt ?? $endedAt)) ? max(0, ($answeredAt ?? $endedAt)->getTimestamp() - $startedAt->getTimestamp()) : null;

        return new NormalizedCall(
            externalId: (string) $c['id'],
            direction: $direction,
            status: $status,
            fromNumber: $direction === 'in' ? $external : $line,
            toNumber: $direction === 'in' ? $line : $external,
            externalNumber: $external,
            startedAt: $startedAt ?? now(),
            answeredAt: $answeredAt,
            endedAt: $endedAt,
            duration: $status === CallStatus::Answered ? $talk : 0,
            ringDuration: $ring,
            recordingUrl: $c['recording'] ?? $c['asset'] ?? null,
            voicemailUrl: $voicemail,
            missedReason: $missedReason,
            hangupBy: isset($c['hangup_by']) ? (string) $c['hangup_by'] : null,
            agentExternalId: isset($c['user']['id']) ? (string) $c['user']['id'] : null,
            agentEmail: $c['user']['email'] ?? null,
            agentNumber: $line,
            isFinal: $done,
            raw: $c,
        );
    }

    private function http(TelephonyProvider $provider): PendingRequest
    {
        if (! $provider->credential('api_id') || ! $provider->credential('api_token')) {
            throw new RuntimeException('API ID / API Token Aircall manquants.');
        }

        return Http::baseUrl(self::BASE)
            ->withBasicAuth($provider->credential('api_id'), $provider->credential('api_token'))
            ->acceptJson()->timeout(30)->retry(2, 1500, throw: false);
    }
}
