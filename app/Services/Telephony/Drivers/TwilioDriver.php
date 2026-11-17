<?php

namespace App\Services\Telephony\Drivers;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\TelephonyAgent;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\PhoneNormalizer;
use App\Services\Telephony\NormalizedCall;
use Filament\Forms;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Twilio Programmable Voice.
 *  - Récupération : API REST Calls (Basic Auth Account SID / Auth Token)
 *  - Temps réel : StatusCallback (signature X-Twilio-Signature vérifiée)
 *  - Click-to-call : Twilio appelle l'agent puis relie le prospect (<Dial>), avec enregistrement optionnel
 */
class TwilioDriver extends TelephonyDriver
{
    public function key(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio';
    }

    public function description(): string
    {
        return 'Account SID et Auth Token : console Twilio → Account Info. Le numéro Twilio sert d\'identifiant d\'appelant.';
    }

    public function credentialFields(): array
    {
        return [
            Forms\Components\TextInput::make('account_sid')->label('Account SID')->required()->startsWith(['AC']),
            Forms\Components\TextInput::make('auth_token')->label('Auth Token')->password()->revealable()->required(),
            Forms\Components\TextInput::make('phone_number')->label('Numéro Twilio (appelant)')->tel()->required()
                ->helperText('Format international, ex : +33 9 70 00 00 00'),
        ];
    }

    public function settingFields(): array
    {
        return [
            Forms\Components\Toggle::make('record_calls')->label('Enregistrer les appels lancés depuis le CRM')->default(true),
        ];
    }

    public function webhookInstructions(TelephonyProvider $provider): ?string
    {
        return 'Console Twilio → <b>Phone Numbers → votre numéro → Voice</b> : renseignez cette URL dans <b>Call status changes</b> (méthode POST). Les appels lancés depuis le CRM l\'utilisent automatiquement.';
    }

    public function supportsClickToCall(TelephonyProvider $provider): bool
    {
        return filled($provider->credential('account_sid')) && filled($provider->credential('phone_number'));
    }

    public function testConnection(TelephonyProvider $provider): string
    {
        $r = $this->http($provider)->get($this->accountPath($provider).'.json');

        if ($r->failed()) {
            throw new RuntimeException("Twilio a répondu {$r->status()} : ".($r->json('message') ?? $r->body()));
        }

        return 'Connexion Twilio réussie (compte « '.$r->json('friendly_name').' »).';
    }

    public function fetchCalls(TelephonyProvider $provider, Carbon $from, Carbon $to): iterable
    {
        $rows = [];
        $uri = $this->accountPath($provider).'/Calls.json?'.http_build_query([
            'StartTime>' => $from->copy()->utc()->subDay()->toDateString(), // filtre au jour près côté Twilio
            'StartTime<' => $to->copy()->utc()->addDay()->toDateString(),
            'PageSize' => 200,
        ]);

        for ($i = 0; $uri && $i < 100; $i++) {
            $r = $this->http($provider)->get($uri);
            if ($r->failed()) {
                throw new RuntimeException("Twilio Calls : HTTP {$r->status()} ".($r->json('message') ?? ''));
            }
            array_push($rows, ...($r->json('calls') ?? []));
            $next = $r->json('next_page_uri');
            $uri = $next ? preg_replace('#^/2010-04-01#', '', $next) : null;
        }

        $bySid = collect($rows)->keyBy('sid');
        $parents = collect($rows)->pluck('parent_call_sid')->filter()->flip();

        foreach ($rows as $row) {
            // Jambe « agent » d'un click-to-call (parent d'un outbound-dial) : on ne garde que la jambe prospect
            if (($row['direction'] ?? '') === 'outbound-api' && $parents->has($row['sid'])) {
                continue;
            }

            $start = NormalizedCall::date($row['start_time'] ?? $row['date_created'] ?? null);
            if ($start && ($start->lt($from) || $start->gt($to))) {
                continue;
            }

            $parent = isset($row['parent_call_sid']) ? $bySid->get($row['parent_call_sid']) : null;
            yield $this->normalizeRest($row, $parent);
        }
    }

    public function verifyWebhook(TelephonyProvider $provider, Request $request): bool
    {
        $signature = (string) $request->header('X-Twilio-Signature');
        $token = (string) $provider->credential('auth_token');

        if ($signature === '' || $token === '') {
            return false;
        }

        // URL exacte appelée par Twilio (sans réordonner la query string)
        $url = $request->getSchemeAndHttpHost().$request->getRequestUri();
        $data = $url;

        if ($request->isMethod('POST')) {
            $params = $request->request->all();
            ksort($params);
            foreach ($params as $k => $v) {
                $data .= $k.(is_array($v) ? implode('', $v) : $v);
            }
        }

        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), $signature);
    }

    public function parseWebhook(TelephonyProvider $provider, Request $request): array
    {
        $p = $request->request->all() + $request->query->all();

        // Fin de la jambe prospect d'un click-to-call (<Dial action=…>)
        if (! empty($p['DialCallSid'])) {
            $status = $this->mapStatus((string) ($p['DialCallStatus'] ?? ''), 'out', (int) ($p['DialCallDuration'] ?? 0));

            return [new NormalizedCall(
                externalId: (string) $p['DialCallSid'],
                direction: 'out',
                status: $status,
                fromNumber: $provider->credential('phone_number'),
                toNumber: $p['to'] ?? null,
                startedAt: now()->subSeconds((int) ($p['DialCallDuration'] ?? 0)),
                endedAt: now(),
                duration: $status === CallStatus::Answered ? (int) ($p['DialCallDuration'] ?? 0) : 0,
                recordingUrl: isset($p['RecordingUrl']) ? $p['RecordingUrl'].'.mp3' : null,
                agentUserId: isset($p['agent']) ? (int) $p['agent'] : null,
                isFinal: true,
                raw: $p,
            )];
        }

        // Enregistrement disponible après coup
        if (! empty($p['RecordingSid']) && ! empty($p['CallSid']) && empty($p['CallStatus'])) {
            return [];
        }

        if (empty($p['CallSid'])) {
            return [];
        }

        // Jambe agent d'un click-to-call : ignorée (la jambe prospect arrive via DialCallSid)
        if (($p['Direction'] ?? '') === 'outbound-api' && ($p['crm'] ?? null) === 'c2c') {
            return [];
        }

        $direction = ($p['Direction'] ?? 'inbound') === 'inbound' ? 'in' : 'out';
        $raw = (string) ($p['CallStatus'] ?? '');
        $duration = (int) ($p['CallDuration'] ?? 0);
        $final = in_array($raw, ['completed', 'busy', 'no-answer', 'failed', 'canceled'], true);

        return [new NormalizedCall(
            externalId: (string) $p['CallSid'],
            direction: $direction,
            status: $final ? $this->mapStatus($raw, $direction, $duration) : CallStatus::InProgress,
            fromNumber: $p['From'] ?? null,
            toNumber: $p['To'] ?? null,
            startedAt: NormalizedCall::date($p['Timestamp'] ?? null)?->subSeconds($duration) ?? now(),
            endedAt: $final ? (NormalizedCall::date($p['Timestamp'] ?? null) ?? now()) : null,
            duration: $raw === 'completed' ? $duration : 0,
            recordingUrl: isset($p['RecordingUrl']) ? $p['RecordingUrl'].'.mp3' : null,
            agentNumber: $direction === 'in' ? ($p['To'] ?? null) : ($p['From'] ?? null),
            isFinal: $final,
            raw: $p,
        )];
    }

    public function webhookResponse(): Response
    {
        // Twilio attend du TwiML sur l'URL d'action d'un <Dial>
        return response('<?xml version="1.0" encoding="UTF-8"?><Response/>', 200, ['Content-Type' => 'text/xml']);
    }

    public function clickToCall(TelephonyProvider $provider, User $user, ?TelephonyAgent $agent, string $number): string
    {
        $agentPhone = $agent?->phone_number ?: $user->phone;
        if (! $agentPhone) {
            throw new RuntimeException('Renseignez le téléphone de l\'agent (Intégrations → Agents) : Twilio l\'appelle d\'abord.');
        }

        $callerId = PhoneNormalizer::normalize($provider->credential('phone_number'));
        $action = $provider->webhookUrl().'?'.http_build_query(['agent' => $user->id, 'to' => $number]);
        $record = $provider->setting('record_calls', true) ? ' record="record-from-answer-dual"' : '';

        $twiml = '<Response><Say language="fr-FR">Mise en relation avec votre prospect.</Say>'
            .'<Dial callerId="'.e($callerId).'" action="'.e($action).'" method="POST"'.$record.'>'.e($number).'</Dial></Response>';

        $r = $this->http($provider)->asForm()->post($this->accountPath($provider).'/Calls.json', [
            'To' => $agentPhone,
            'From' => $callerId,
            'Twiml' => $twiml,
            'StatusCallback' => $provider->webhookUrl().'?crm=c2c',
        ]);

        if ($r->failed()) {
            throw new RuntimeException('Twilio : '.($r->json('message') ?? $r->body()));
        }

        return 'Twilio appelle votre téléphone : décrochez pour joindre le prospect.';
    }

    public function recordingSource(TelephonyProvider $provider, Call $call, bool $voicemail = false): ?array
    {
        $url = $call->recording_url;

        // Enregistrement non connu (appel récupéré par synchronisation) : on le cherche
        if (! $url && $call->external_id) {
            $r = $this->http($provider)->get($this->accountPath($provider).'/Recordings.json', ['CallSid' => $call->external_id]);
            $uri = $r->json('recordings.0.uri');
            $url = $uri ? 'https://api.twilio.com'.str_replace('.json', '.mp3', $uri) : null;
        }

        if (! $url) {
            return null;
        }

        // Les identifiants Twilio ne sont envoyés qu'aux serveurs de Twilio
        $host = (string) parse_url($url, PHP_URL_HOST);
        $isTwilio = $host === 'twilio.com' || str_ends_with($host, '.twilio.com');

        return $isTwilio ? ['url' => $url, 'auth' => [$provider->credential('account_sid'), $provider->credential('auth_token')]] : ['url' => $url];
    }

    public function normalizeRest(array $row, ?array $parent = null): NormalizedCall
    {
        $dir = $row['direction'] ?? 'inbound';
        $direction = $dir === 'inbound' ? 'in' : 'out';
        $duration = (int) ($row['duration'] ?? 0);
        $raw = (string) ($row['status'] ?? '');
        $final = in_array($raw, ['completed', 'busy', 'no-answer', 'failed', 'canceled'], true);
        $status = $final ? $this->mapStatus($raw, $direction, $duration) : CallStatus::InProgress;

        // Pour une jambe outbound-dial, l'agent est le destinataire de l'appel parent
        $agentNumber = $direction === 'in' ? ($row['to'] ?? null) : ($parent['to'] ?? $row['from'] ?? null);

        return new NormalizedCall(
            externalId: (string) $row['sid'],
            direction: $direction,
            status: $status,
            fromNumber: $row['from'] ?? null,
            toNumber: $row['to'] ?? null,
            startedAt: NormalizedCall::date($row['start_time'] ?? $row['date_created'] ?? null) ?? now(),
            endedAt: NormalizedCall::date($row['end_time'] ?? null),
            duration: $status === CallStatus::Answered ? $duration : 0,
            agentNumber: $agentNumber,
            isFinal: $final,
            raw: $row,
        );
    }

    private function mapStatus(string $raw, string $direction, int $duration): CallStatus
    {
        return match ($raw) {
            'completed', 'answered' => $duration > 0 ? CallStatus::Answered : ($direction === 'in' ? CallStatus::Missed : CallStatus::NoAnswer),
            'busy' => CallStatus::Busy,
            'failed' => CallStatus::Failed,
            'canceled' => $direction === 'in' ? CallStatus::Missed : CallStatus::Cancelled,
            default => $direction === 'in' ? CallStatus::Missed : CallStatus::NoAnswer,
        };
    }

    private function accountPath(TelephonyProvider $provider): string
    {
        return '/Accounts/'.urlencode((string) $provider->credential('account_sid'));
    }

    private function http(TelephonyProvider $provider): PendingRequest
    {
        if (! $provider->credential('account_sid') || ! $provider->credential('auth_token')) {
            throw new RuntimeException('Account SID / Auth Token Twilio manquants.');
        }

        return Http::baseUrl('https://api.twilio.com/2010-04-01')
            ->withBasicAuth($provider->credential('account_sid'), $provider->credential('auth_token'))
            ->acceptJson()->timeout(30)->retry(2, 1500, throw: false);
    }
}
