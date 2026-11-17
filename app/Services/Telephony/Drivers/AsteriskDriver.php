<?php

namespace App\Services\Telephony\Drivers;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\TelephonyAgent;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\PhoneNormalizer;
use App\Services\Telephony\AmiClient;
use App\Services\Telephony\NormalizedCall;
use Filament\Forms;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Asterisk / FreePBX / Issabel.
 *  - Récupération : lecture de la table CDR (base asteriskcdrdb), regroupée par linkedid
 *  - Click-to-call : AMI (Originate) — le poste de l'agent sonne puis le numéro est composé
 *  - Enregistrements : URL construite à partir de recordingfile
 */
class AsteriskDriver extends TelephonyDriver
{
    public function key(): string
    {
        return 'asterisk';
    }

    public function label(): string
    {
        return 'Asterisk / FreePBX';
    }

    public function description(): string
    {
        return 'Accès en lecture à la base CDR (MySQL) et, pour le click-to-call, un utilisateur AMI (manager.conf ou FreePBX → Asterisk Manager Users).';
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    public function credentialFields(): array
    {
        return [
            Forms\Components\Fieldset::make('Base CDR (récupération des appels)')->columns(3)->schema([
                Forms\Components\Select::make('db_driver')->label('Type')->options(['mysql' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'])->default('mysql')->required(),
                Forms\Components\TextInput::make('db_host')->label('Hôte')->placeholder('10.0.0.5'),
                Forms\Components\TextInput::make('db_port')->label('Port')->numeric()->default(3306),
                Forms\Components\TextInput::make('db_database')->label('Base')->default('asteriskcdrdb')->required(),
                Forms\Components\TextInput::make('db_username')->label('Utilisateur'),
                Forms\Components\TextInput::make('db_password')->label('Mot de passe')->password()->revealable(),
                Forms\Components\TextInput::make('db_table')->label('Table')->default('cdr')->required(),
            ]),
            Forms\Components\Fieldset::make('AMI (click-to-call)')->columns(4)->schema([
                Forms\Components\TextInput::make('ami_host')->label('Hôte'),
                Forms\Components\TextInput::make('ami_port')->label('Port')->numeric()->default(5038),
                Forms\Components\TextInput::make('ami_username')->label('Utilisateur'),
                Forms\Components\TextInput::make('ami_secret')->label('Secret')->password()->revealable(),
            ]),
            Forms\Components\Fieldset::make('Enregistrements (facultatif)')->columns(3)->schema([
                Forms\Components\TextInput::make('recordings_url')->label('URL des enregistrements')->columnSpanFull()
                    ->placeholder('https://pbx.example.fr/recordings/{Y}/{m}/{d}/{file}')
                    ->helperText('{file} = recordingfile du CDR ; {Y} {m} {d} = date de l\'appel.'),
                Forms\Components\TextInput::make('recordings_username')->label('Utilisateur HTTP'),
                Forms\Components\TextInput::make('recordings_password')->label('Mot de passe HTTP')->password()->revealable(),
            ]),
        ];
    }

    public function settingFields(): array
    {
        return [
            Forms\Components\TextInput::make('internal_max_length')->label('Longueur max. d\'un poste interne')->numeric()->default(4)
                ->helperText('Un numéro de cette longueur ou moins est considéré comme un poste (ex. 201).'),
            Forms\Components\TextInput::make('outbound_prefix')->label('Préfixe de sortie à retirer')->placeholder('0 ou 9')
                ->helperText('Si vos utilisateurs composent un préfixe pour sortir.'),
            Forms\Components\Select::make('pbx_timezone')->label('Fuseau horaire du PBX')->default('Europe/Paris')
                ->options(['Europe/Paris' => 'Europe/Paris', 'UTC' => 'UTC', 'Africa/Douala' => 'Africa/Douala']),
            Forms\Components\TextInput::make('originate_context')->label('Contexte de numérotation (AMI)')->default('from-internal'),
            Forms\Components\Select::make('channel_tech')->label('Technologie des postes')->default('PJSIP')->options(['PJSIP' => 'PJSIP', 'SIP' => 'SIP', 'IAX2' => 'IAX2']),
            Forms\Components\Select::make('dial_format')->label('Format du numéro composé')->default('national')
                ->options(['national' => 'National (0387…)', 'e164' => 'International (+33387…)', 'e164_00' => 'International (0033387…)']),
        ];
    }

    public function supportsClickToCall(TelephonyProvider $provider): bool
    {
        return filled($provider->credential('ami_host')) && filled($provider->credential('ami_username'));
    }

    public function testConnection(TelephonyProvider $provider): string
    {
        $messages = [];

        $count = $this->db($provider)->table($provider->credential('db_table', 'cdr'))
            ->where('calldate', '>=', now($this->tz($provider))->subDay()->format('Y-m-d H:i:s'))->count();
        $messages[] = "Base CDR OK ({$count} lignes sur 24 h).";

        if ($this->supportsClickToCall($provider)) {
            $ami = $this->ami($provider);
            $ami->login();
            $ami->logoff();
            $messages[] = 'AMI OK.';
        }

        return implode(' ', $messages);
    }

    public function fetchCalls(TelephonyProvider $provider, Carbon $from, Carbon $to): iterable
    {
        $tz = $this->tz($provider);
        $rows = $this->db($provider)->table($provider->credential('db_table', 'cdr'))
            ->whereBetween('calldate', [$from->copy()->setTimezone($tz)->format('Y-m-d H:i:s'), $to->copy()->setTimezone($tz)->format('Y-m-d H:i:s')])
            ->orderBy('calldate')
            ->limit(20000)
            ->get()
            ->map(fn ($r) => (array) $r);

        // Un appel = toutes les lignes CDR partageant le même linkedid (files d'attente, groupes d'appel, transferts)
        foreach ($rows->groupBy(fn ($r) => $r['linkedid'] ?? $r['uniqueid']) as $id => $legs) {
            if ($call = $this->normalizeGroup($provider, (string) $id, $legs->values()->all())) {
                yield $call;
            }
        }
    }

    /** @param array<int, array<string, mixed>> $legs */
    public function normalizeGroup(TelephonyProvider $provider, string $id, array $legs): ?NormalizedCall
    {
        $max = (int) $provider->setting('internal_max_length', 4);
        $isInternal = fn (?string $n) => $n !== null && $n !== '' && strlen(preg_replace('/\D/', '', $n)) <= $max && ctype_digit(preg_replace('/\D/', '', $n));
        $first = $legs[0];

        $src = (string) ($first['src'] ?? '');
        $dst = (string) ($first['dst'] ?? '');

        if ($isInternal($src) && ! $isInternal($dst)) {
            $direction = 'out';
            $external = $this->stripPrefix($provider, $dst);
            $agentExt = $src;
        } elseif (! $isInternal($src) && $src !== '') {
            $direction = 'in';
            $external = $src;
            $agentExt = null;
        } else {
            return null; // appel interne poste à poste, ou ligne technique
        }

        $isVoicemailLeg = fn ($l) => stripos((string) ($l['lastapp'] ?? ''), 'voicemail') !== false
            || str_starts_with((string) ($l['dst'] ?? ''), 'vm');
        $isVoicemail = collect($legs)->contains($isVoicemailLeg);

        // Jambe réellement décrochée par un humain (la messagerie « décroche » aussi dans le CDR)
        $answeredLeg = collect($legs)->first(fn ($l) => strtoupper((string) $l['disposition']) === 'ANSWERED' && (int) $l['billsec'] > 0 && ! $isVoicemailLeg($l));

        if ($direction === 'in') {
            // Poste qui a décroché, sinon premier poste ayant sonné
            $channels = collect([$answeredLeg['dstchannel'] ?? null])->merge(collect($legs)->pluck('dstchannel'))->filter();
            foreach ($channels as $chan) {
                if (preg_match('#^[A-Z0-9]+/(\d+)[-@]#i', (string) $chan, $m) && $isInternal($m[1])) {
                    $agentExt = $m[1];
                    break;
                }
            }
            if (! $agentExt && $answeredLeg && $isInternal((string) $answeredLeg['dst'])) {
                $agentExt = (string) $answeredLeg['dst'];
            }
        }

        $dispositions = collect($legs)->pluck('disposition')->map(fn ($d) => strtoupper((string) $d));

        $status = match (true) {
            $answeredLeg !== null => CallStatus::Answered,
            $isVoicemail && $direction === 'in' => CallStatus::Voicemail,
            $dispositions->contains('BUSY') => CallStatus::Busy,
            $dispositions->contains('FAILED') || $dispositions->contains('CONGESTION') => CallStatus::Failed,
            default => $direction === 'in' ? CallStatus::Missed : CallStatus::NoAnswer,
        };

        $tz = $this->tz($provider);
        $startedAt = NormalizedCall::date($first['calldate'], $tz);
        $total = (int) collect($legs)->max('duration');
        $talk = (int) ($answeredLeg['billsec'] ?? 0);
        $recording = $answeredLeg['recordingfile'] ?? collect($legs)->pluck('recordingfile')->filter()->first();

        return new NormalizedCall(
            externalId: $id,
            direction: $direction,
            status: $status,
            fromNumber: $direction === 'out' ? null : $external,
            toNumber: $direction === 'out' ? $external : null,
            externalNumber: $external,
            startedAt: $startedAt,
            answeredAt: $answeredLeg && $startedAt ? $startedAt->copy()->addSeconds(max(0, (int) $answeredLeg['duration'] - $talk)) : null,
            endedAt: $startedAt?->copy()->addSeconds($total),
            duration: $status === CallStatus::Answered ? $talk : 0,
            ringDuration: max(0, $total - $talk),
            recordingUrl: $recording ? $this->recordingUrl($provider, (string) $recording, $startedAt) : null,
            agentExtension: $agentExt,
            isFinal: true,
            raw: ['legs' => $legs],
        );
    }

    public function clickToCall(TelephonyProvider $provider, User $user, ?TelephonyAgent $agent, string $number): string
    {
        if (! $agent?->extension) {
            throw new RuntimeException('Renseignez le poste (extension) de l\'agent : Intégrations → Agents.');
        }

        $ami = $this->ami($provider);
        $ami->login();
        $response = $ami->originate([
            'Channel' => $provider->setting('channel_tech', 'PJSIP').'/'.$agent->extension,
            'Context' => $provider->setting('originate_context', 'from-internal'),
            'Exten' => $this->dialString($provider, $number),
            'Priority' => 1,
            'CallerID' => 'CRM <'.$this->dialString($provider, $number).'>',
            'Timeout' => 30000,
            'Async' => 'true',
        ]);
        $ami->logoff();

        if (stripos($response, 'Response: Success') === false) {
            throw new RuntimeException('Asterisk a refusé l\'appel : '.trim($response));
        }

        return "Votre poste {$agent->extension} sonne : décrochez pour joindre le prospect.";
    }

    public function recordingSource(TelephonyProvider $provider, Call $call, bool $voicemail = false): ?array
    {
        $src = parent::recordingSource($provider, $call, $voicemail);

        if ($src && $provider->credential('recordings_username')) {
            $src['auth'] = [$provider->credential('recordings_username'), (string) $provider->credential('recordings_password')];
        }

        return $src;
    }

    public function dialString(TelephonyProvider $provider, string $number): string
    {
        $e164 = PhoneNormalizer::normalize($number) ?? $number;
        $digits = ltrim($e164, '+');

        return match ($provider->setting('dial_format', 'national')) {
            'e164' => $e164,
            'e164_00' => '00'.$digits,
            default => str_starts_with($e164, '+33') ? '0'.substr($e164, 3) : '00'.$digits,
        };
    }

    private function recordingUrl(TelephonyProvider $provider, string $file, ?Carbon $date): ?string
    {
        $template = $provider->credential('recordings_url');
        if (! $template) {
            return null;
        }

        $date ??= now();

        return strtr($template, ['{file}' => rawurlencode(basename($file)), '{Y}' => $date->format('Y'), '{m}' => $date->format('m'), '{d}' => $date->format('d')]);
    }

    private function stripPrefix(TelephonyProvider $provider, string $number): string
    {
        $prefix = (string) $provider->setting('outbound_prefix', '');

        // Ne retire pas le 0 d'un numéro national à 10 chiffres
        if ($prefix !== '' && str_starts_with($number, $prefix) && strlen($number) > 10) {
            return substr($number, strlen($prefix));
        }

        return $number;
    }

    private function tz(TelephonyProvider $provider): string
    {
        return (string) $provider->setting('pbx_timezone', 'Europe/Paris');
    }

    public function db(TelephonyProvider $provider): Connection
    {
        $name = 'telephony_cdr_'.$provider->id;
        $driver = $provider->credential('db_driver', 'mysql');

        config(["database.connections.{$name}" => [
            'driver' => $driver,
            'host' => $provider->credential('db_host', '127.0.0.1'),
            'port' => $provider->credential('db_port', $driver === 'pgsql' ? 5432 : 3306),
            'database' => $provider->credential('db_database', 'asteriskcdrdb'),
            'username' => $provider->credential('db_username'),
            'password' => $provider->credential('db_password'),
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'prefix' => '',
            'options' => $driver === 'mysql' ? [\PDO::ATTR_TIMEOUT => 10] : [],
        ]]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function ami(TelephonyProvider $provider): AmiClient
    {
        if (! $this->supportsClickToCall($provider)) {
            throw new RuntimeException('Paramètres AMI manquants.');
        }

        return new AmiClient(
            (string) $provider->credential('ami_host'),
            (int) $provider->credential('ami_port', 5038),
            (string) $provider->credential('ami_username'),
            (string) $provider->credential('ami_secret'),
        );
    }
}
