<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Enums\LeadStatus;
use App\Filament\Resources\TelephonyProviderResource;
use App\Models\Call;
use App\Models\Lead;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\Telephony\AmiClient;
use App\Services\Telephony\CallSynchronizer;
use App\Services\Telephony\Drivers\AsteriskDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TelephonyTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $driver, array $credentials = [], array $settings = []): TelephonyProvider
    {
        return TelephonyProvider::create(['driver' => $driver, 'name' => ucfirst($driver), 'credentials' => $credentials, 'settings' => $settings]);
    }

    // ------------------------------------------------------------------ Aircall

    public function test_aircall_webhook_verifies_token_and_creates_callback_task_for_missed_inbound(): void
    {
        $owner = User::factory()->create();
        $lead = Lead::factory()->create(['phone' => '03 87 00 00 11', 'assigned_to' => $owner->id, 'status' => LeadStatus::AAppeler]);
        $p = $this->provider('aircall', ['api_id' => 'id', 'api_token' => 'tok', 'webhook_token' => 'aircall-secret']);

        $payload = ['resource' => 'call', 'event' => 'call.ended', 'timestamp' => time(), 'token' => 'aircall-secret', 'data' => [
            'id' => 812, 'direction' => 'inbound', 'status' => 'done', 'missed_call_reason' => 'agents_did_not_answer',
            'started_at' => now()->subMinute()->timestamp, 'answered_at' => null, 'ended_at' => now()->timestamp,
            'duration' => 25, 'raw_digits' => '+33 3 87 00 00 11', 'number' => ['digits' => '+33 1 00 00 00 00'], 'voicemail' => null,
        ]];

        $this->postJson('/webhooks/telephony/'.$p->webhook_token, ['token' => 'wrong'] + $payload)->assertUnauthorized();
        $this->postJson('/webhooks/telephony/'.$p->webhook_token, $payload)->assertOk();

        $call = Call::sole();
        $this->assertSame(CallStatus::Missed, $call->status);
        $this->assertSame('agents_did_not_answer', $call->missed_reason);
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame('in', $call->direction);

        // Rappel automatique haute priorité + notification au commercial du lead
        $task = $lead->tasks()->sole();
        $this->assertSame('Rappeler : appel manqué', $task->title);
        $this->assertSame($owner->id, $task->user_id);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(0, $lead->fresh()->call_attempts);
    }

    public function test_aircall_sync_paginates_and_maps_statuses(): void
    {
        $agent = User::factory()->create();
        $p = $this->provider('aircall', ['api_id' => 'id', 'api_token' => 'tok']);
        $p->agents()->create(['user_id' => $agent->id, 'external_id' => '555']);
        Lead::factory()->create(['phone' => '0387000021']);

        $call = fn (int $id, array $extra) => $extra + ['id' => $id, 'status' => 'done', 'started_at' => now()->subHour()->timestamp, 'ended_at' => now()->subHour()->addMinutes(3)->timestamp, 'raw_digits' => '+33387000021', 'number' => ['digits' => '+33100000000'], 'user' => ['id' => 555]];

        Http::fake([
            'api.aircall.io/v1/calls?*page=1*' => Http::response(['calls' => [
                $call(1, ['direction' => 'outbound', 'answered_at' => now()->subHour()->addSeconds(20)->timestamp]),
                $call(2, ['direction' => 'outbound', 'answered_at' => null, 'missed_call_reason' => 'no_answer']),
            ], 'meta' => ['next_page_link' => 'https://api.aircall.io/v1/calls?page=2']]),
            'api.aircall.io/v1/calls?*page=2*' => Http::response(['calls' => [
                $call(3, ['direction' => 'inbound', 'answered_at' => null, 'missed_call_reason' => 'out_of_opening_hours', 'voicemail' => 'https://aircall/vm.mp3']),
            ], 'meta' => ['next_page_link' => null]]),
        ]);

        $count = app(CallSynchronizer::class)->sync($p, now()->subDay());

        $this->assertSame(3, $count);
        $this->assertSame(CallStatus::Answered, Call::where('external_id', '1')->value('status'));
        $this->assertSame(160, Call::where('external_id', '1')->value('duration'));
        $this->assertSame(CallStatus::NoAnswer, Call::where('external_id', '2')->value('status'));
        $this->assertSame(CallStatus::Voicemail, Call::where('external_id', '3')->value('status'));
        $this->assertSame(3, Call::where('user_id', $agent->id)->count());
        $this->assertSame('ok', $p->fresh()->last_sync_status);

        // Re-synchronisation : rien n'est dupliqué
        app(CallSynchronizer::class)->sync($p, now()->subDay());
        $this->assertSame(3, Call::count());
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('id:tok')));
    }

    // ------------------------------------------------------------------ Ringover

    public function test_ringover_sync_maps_answered_unreachable_and_voicemail(): void
    {
        $p = $this->provider('ringover', ['api_key' => 'rk', 'region' => 'eu']);
        $lead = Lead::factory()->create(['phone' => '0387000031', 'status' => LeadStatus::AAppeler]);

        $row = fn (string $id, array $extra) => $extra + ['call_id' => $id, 'start_time' => now()->subHour()->toIso8601String(), 'end_time' => now()->subHour()->addMinute()->toIso8601String(), 'from_number' => '33100000000', 'to_number' => '33387000031', 'direction' => 'out'];

        Http::fake(['public-api.ringover.com/v2/calls*' => Http::response(['call_list' => [
            $row('r1', ['last_state' => 'ANSWERED', 'is_answered' => true, 'incall_duration' => 95, 'total_duration' => 110, 'record' => 'https://cdn.ringover/r1.mp3']),
            $row('r2', ['last_state' => 'MISSED', 'is_answered' => false]),
            $row('r3', ['direction' => 'in', 'from_number' => '33387000031', 'to_number' => '33100000000', 'last_state' => 'VOICEMAIL', 'voicemail' => 'https://cdn.ringover/vm.mp3']),
        ]])]);

        app(CallSynchronizer::class)->sync($p, now()->subDay());

        $this->assertSame(CallStatus::Answered, Call::where('external_id', 'r1')->value('status'));
        $this->assertSame(15, Call::where('external_id', 'r1')->value('ring_duration'));
        $this->assertSame(CallStatus::NoAnswer, Call::where('external_id', 'r2')->value('status'));
        $this->assertSame(CallStatus::Voicemail, Call::where('external_id', 'r3')->value('status'));
        $this->assertSame(3, Call::where('lead_id', $lead->id)->count());
        $this->assertSame(2, $lead->fresh()->call_attempts);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'rk'));
    }

    public function test_finished_call_does_not_go_back_to_in_progress(): void
    {
        $p = $this->provider('ringover', ['api_key' => 'rk']);
        $url = '/webhooks/telephony/'.$p->webhook_token;

        $this->postJson($url, ['event' => 'hangup', 'data' => ['call_id' => 'x1', 'direction' => 'out', 'to_number' => '33387000041', 'is_answered' => true, 'incall_duration' => 30, 'end_time' => now()->toIso8601String()]])->assertOk();
        $this->postJson($url, ['event' => 'ringing', 'data' => ['call_id' => 'x1', 'direction' => 'out', 'to_number' => '33387000041']])->assertOk();

        $this->assertSame(CallStatus::Answered, Call::sole()->status);
    }

    // ------------------------------------------------------------------ Twilio

    private function twilioSign(string $url, array $params, string $token): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    public function test_twilio_status_callback_checks_signature(): void
    {
        $p = $this->provider('twilio', ['account_sid' => 'ACx', 'auth_token' => 'twtok', 'phone_number' => '+33970000000']);
        $url = 'http://localhost/webhooks/telephony/'.$p->webhook_token;
        $params = ['CallSid' => 'CA1', 'CallStatus' => 'no-answer', 'Direction' => 'outbound-api', 'From' => '+33970000000', 'To' => '+33387000051', 'CallDuration' => '0'];

        $this->post($url, $params, ['X-Twilio-Signature' => 'bad'])->assertUnauthorized();
        $this->post($url, $params, ['X-Twilio-Signature' => $this->twilioSign($url, $params, 'twtok')])
            ->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $this->assertSame(CallStatus::NoAnswer, Call::sole()->status);
        $this->assertSame('+33387000051', Call::sole()->external_number);
    }

    public function test_twilio_click_to_call_and_dial_result(): void
    {
        $agent = User::factory()->create();
        $p = $this->provider('twilio', ['account_sid' => 'ACx', 'auth_token' => 'twtok', 'phone_number' => '+33970000000'], ['record_calls' => true]);
        $p->agents()->create(['user_id' => $agent->id, 'phone_number' => '06 12 34 56 78']);
        $lead = Lead::factory()->create(['phone' => '0387000061']);

        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'CAparent'], 201)]);
        $p->driver()->clickToCall($p, $agent, $p->agentFor($agent), '+33387000061');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/Accounts/ACx/Calls.json')
            && $r['To'] === '+33612345678' && $r['From'] === '+33970000000'
            && str_contains($r['Twiml'], '<Dial') && str_contains($r['Twiml'], '+33387000061') && str_contains($r['Twiml'], 'record-from-answer-dual'));

        // Résultat de la jambe prospect (action du <Dial>)
        $url = $p->webhookUrl().'?'.http_build_query(['agent' => $agent->id, 'to' => '+33387000061']);
        $params = ['CallSid' => 'CAparent', 'DialCallSid' => 'CAchild', 'DialCallStatus' => 'completed', 'DialCallDuration' => '73', 'RecordingUrl' => 'https://api.twilio.com/rec/RE1'];
        $this->post($url, $params, ['X-Twilio-Signature' => $this->twilioSign($url, $params, 'twtok')])->assertOk();

        $call = Call::sole();
        $this->assertSame('CAchild', $call->external_id);
        $this->assertSame(CallStatus::Answered, $call->status);
        $this->assertSame(73, $call->duration);
        $this->assertSame($agent->id, $call->user_id);
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame('https://api.twilio.com/rec/RE1.mp3', $call->recording_url);
    }

    public function test_twilio_sync_skips_agent_leg(): void
    {
        $p = $this->provider('twilio', ['account_sid' => 'ACx', 'auth_token' => 'twtok', 'phone_number' => '+33970000000']);
        $agent = User::factory()->create();
        $p->agents()->create(['user_id' => $agent->id, 'phone_number' => '+33612345678']);
        $t = now()->subHour()->toRfc2822String();

        Http::fake(['api.twilio.com/*' => Http::response(['calls' => [
            ['sid' => 'CAagent', 'parent_call_sid' => null, 'direction' => 'outbound-api', 'from' => '+33970000000', 'to' => '+33612345678', 'status' => 'completed', 'duration' => '80', 'start_time' => $t],
            ['sid' => 'CAprospect', 'parent_call_sid' => 'CAagent', 'direction' => 'outbound-dial', 'from' => '+33970000000', 'to' => '+33387000071', 'status' => 'busy', 'duration' => '0', 'start_time' => $t],
            ['sid' => 'CAin', 'parent_call_sid' => null, 'direction' => 'inbound', 'from' => '+33387000072', 'to' => '+33970000000', 'status' => 'completed', 'duration' => '41', 'start_time' => $t],
        ], 'next_page_uri' => null])]);

        app(CallSynchronizer::class)->sync($p, now()->subDay());

        $this->assertEqualsCanonicalizing(['CAprospect', 'CAin'], Call::pluck('external_id')->all());
        $this->assertSame(CallStatus::Busy, Call::where('external_id', 'CAprospect')->value('status'));
        // L'agent est retrouvé grâce à la jambe parente (son téléphone)
        $this->assertSame($agent->id, Call::where('external_id', 'CAprospect')->value('user_id'));
        $this->assertSame(CallStatus::Answered, Call::where('external_id', 'CAin')->value('status'));
    }

    // ------------------------------------------------------------------ Asterisk

    public function test_asterisk_reads_cdr_and_groups_legs(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cdr').'.sqlite';
        touch($file);
        $agent = User::factory()->create();
        $p = $this->provider('asterisk', ['db_driver' => 'sqlite', 'db_database' => $file, 'db_table' => 'cdr'], ['internal_max_length' => 4, 'outbound_prefix' => '0', 'pbx_timezone' => 'Europe/Paris']);
        $p->agents()->create(['user_id' => $agent->id, 'extension' => '201']);
        $lead = Lead::factory()->create(['phone' => '0387000081']);

        $db = app(AsteriskDriver::class)->db($p);
        $db->statement('CREATE TABLE cdr (calldate TEXT, src TEXT, dst TEXT, dcontext TEXT, channel TEXT, dstchannel TEXT, lastapp TEXT, duration INT, billsec INT, disposition TEXT, uniqueid TEXT, linkedid TEXT, recordingfile TEXT)');
        $at = now('Europe/Paris')->subHour()->format('Y-m-d H:i:s');
        $rows = [
            // Sortant décroché depuis le poste 201
            ['calldate' => $at, 'src' => '201', 'dst' => '0387000081', 'dstchannel' => 'PJSIP/trunk-01', 'lastapp' => 'Dial', 'duration' => 70, 'billsec' => 60, 'disposition' => 'ANSWERED', 'uniqueid' => 'u1', 'linkedid' => 'L1', 'recordingfile' => 'out-0387000081-201.wav'],
            // Entrant : groupe d'appel, deux postes sonnent, personne ne répond, messagerie
            ['calldate' => $at, 'src' => '0387000081', 'dst' => '600', 'dstchannel' => 'PJSIP/201-0001', 'lastapp' => 'Dial', 'duration' => 20, 'billsec' => 0, 'disposition' => 'NO ANSWER', 'uniqueid' => 'u2', 'linkedid' => 'L2', 'recordingfile' => ''],
            ['calldate' => $at, 'src' => '0387000081', 'dst' => 'vmu201', 'dstchannel' => '', 'lastapp' => 'VoiceMail', 'duration' => 30, 'billsec' => 25, 'disposition' => 'ANSWERED', 'uniqueid' => 'u3', 'linkedid' => 'L2', 'recordingfile' => ''],
            // Sortant occupé
            ['calldate' => $at, 'src' => '201', 'dst' => '00612345678', 'dstchannel' => '', 'lastapp' => 'Dial', 'duration' => 5, 'billsec' => 0, 'disposition' => 'BUSY', 'uniqueid' => 'u4', 'linkedid' => 'L4', 'recordingfile' => ''],
            // Interne poste à poste : ignoré
            ['calldate' => $at, 'src' => '201', 'dst' => '202', 'dstchannel' => 'PJSIP/202-01', 'lastapp' => 'Dial', 'duration' => 10, 'billsec' => 8, 'disposition' => 'ANSWERED', 'uniqueid' => 'u5', 'linkedid' => 'L5', 'recordingfile' => ''],
        ];
        foreach ($rows as $r) {
            $db->table('cdr')->insert($r + ['dcontext' => 'from-internal', 'channel' => 'PJSIP/x']);
        }

        app(CallSynchronizer::class)->sync($p, now()->subDay());

        $this->assertSame(3, Call::count());
        $out = Call::where('external_id', 'L1')->first();
        $this->assertSame(CallStatus::Answered, $out->status);
        $this->assertSame(60, $out->duration);
        $this->assertSame(10, $out->ring_duration);
        $this->assertSame($agent->id, $out->user_id);
        $this->assertSame($lead->id, $out->lead_id);

        $in = Call::where('external_id', 'L2')->first();
        $this->assertSame(CallStatus::Voicemail, $in->status);
        $this->assertSame('in', $in->direction);
        $this->assertSame($agent->id, $in->user_id); // poste 201 via dstchannel

        $this->assertSame(CallStatus::Busy, Call::where('external_id', 'L4')->value('status'));
        $this->assertSame('+33612345678', Call::where('external_id', 'L4')->value('external_number'));

        DB::purge('telephony_cdr_'.$p->id);
        @unlink($file);
    }

    public function test_asterisk_dial_format_and_ami_encoding(): void
    {
        $p = $this->provider('asterisk', [], ['dial_format' => 'national']);
        $driver = app(AsteriskDriver::class);

        $this->assertSame('0387000091', $driver->dialString($p, '+33387000091'));
        $this->assertSame('0035220304050', $driver->dialString($p, '+35220304050'));
        $this->assertSame("Action: Originate\r\nChannel: PJSIP/201\r\nExten: 0387000091\r\n\r\n",
            AmiClient::encode(['Action' => 'Originate', 'Channel' => 'PJSIP/201', 'Exten' => "0387000091\r\n"]));
    }

    // ------------------------------------------------------------------ Webhook générique

    public function test_generic_webhook_hmac_and_custom_mapping(): void
    {
        $p = $this->provider('generic', ['auth_mode' => 'hmac', 'auth_header' => 'X-Signature', 'auth_secret' => 's3cr3t'], ['mapping' => ['duration' => 'payload.billsec']]);
        Lead::factory()->create(['phone' => '0387000101']);

        $body = json_encode(['call_id' => 'g1', 'direction' => 'outbound', 'from' => '201', 'to' => '03 87 00 01 01', 'status' => 'ANSWERED', 'payload' => ['billsec' => 42]]);
        $url = '/webhooks/telephony/'.$p->webhook_token;

        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'nope'], $body)->assertUnauthorized();
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 's3cr3t')], $body)->assertOk();

        $call = Call::sole();
        $this->assertSame(CallStatus::Answered, $call->status);
        $this->assertSame(42, $call->duration);
        $this->assertNotNull($call->lead_id);
    }

    public function test_generic_click_to_call_url_template(): void
    {
        $user = User::factory()->create();
        $p = $this->provider('generic', ['click_to_call_url' => 'https://pbx.test/dial?ext={extension}&n={number_national}']);
        $p->agents()->create(['user_id' => $user->id, 'extension' => '205']);
        Http::fake(['pbx.test/*' => Http::response('OK')]);

        $p->driver()->clickToCall($p, $user, $p->agentFor($user), '+33387000111');

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://pbx.test/dial?ext=205&n=0387000111');
    }

    // ------------------------------------------------------------------ Click-to-call & UI

    public function test_ringover_and_aircall_click_to_call(): void
    {
        $user = User::factory()->create();
        $ring = $this->provider('ringover', ['api_key' => 'rk']);
        $ring->agents()->create(['user_id' => $user->id, 'phone_number' => '+33100000001']);
        $air = $this->provider('aircall', ['api_id' => 'i', 'api_token' => 't']);
        $air->agents()->create(['user_id' => $user->id, 'external_id' => '777']);

        Http::fake(['*' => Http::response([], 200)]);
        $ring->driver()->clickToCall($ring, $user, $ring->agentFor($user), '+33387000121');
        $air->driver()->clickToCall($air, $user, $air->agentFor($user), '+33387000121');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/callback') && $r['from_number'] === 33100000001 && $r['to_number'] === 33387000121);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.aircall.io/v1/users/777/dial' && $r['to'] === '+33387000121');
    }

    public function test_admin_creates_provider_with_encrypted_credentials(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Http::fake(['api.aircall.io/*' => Http::response(['ping' => 'pong'])]);

        // Les valeurs par défaut du service choisi sont appliquées (ex. Twilio : enregistrement activé)
        Livewire::test(TelephonyProviderResource\Pages\CreateTelephonyProvider::class)
            ->fillForm(['driver' => 'twilio'])
            ->assertFormSet(['settings.record_calls' => true]);

        Livewire::test(TelephonyProviderResource\Pages\CreateTelephonyProvider::class)
            ->fillForm(['driver' => 'aircall'])
            ->assertFormSet(['name' => 'Aircall'])
            ->fillForm(['credentials' => ['api_id' => 'my-id', 'api_token' => 'my-token', 'webhook_token' => 'wh'], 'is_default' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = TelephonyProvider::sole();
        $this->assertSame('my-token', $p->credential('api_token'));
        $this->assertTrue($p->is_default);
        $this->assertStringNotContainsString('my-token', (string) DB::table('telephony_providers')->value('credentials'));

        $this->get(TelephonyProviderResource::getUrl('edit', ['record' => $p]))->assertOk()->assertSee($p->webhookUrl());

        // L'édition affiche les paramètres existants et ne les efface pas à l'enregistrement
        Livewire::test(TelephonyProviderResource\Pages\EditTelephonyProvider::class, ['record' => $p->getRouteKey()])
            ->assertFormSet(['credentials.api_id' => 'my-id', 'credentials.api_token' => 'my-token'])
            ->fillForm(['name' => 'Aircall équipe'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('my-token', $p->fresh()->credential('api_token'));
        $this->assertSame('Aircall équipe', $p->fresh()->name);
        $this->get('/telephony-providers')->assertOk();

        $this->actingAs(User::factory()->create());
        $this->get('/telephony-providers')->assertForbidden();
    }

    public function test_recording_proxy_uses_provider_auth_and_respects_visibility(): void
    {
        $owner = User::factory()->create();
        $p = $this->provider('twilio', ['account_sid' => 'ACx', 'auth_token' => 'twtok', 'phone_number' => '+33970000000']);
        $call = Call::create(['telephony_provider_id' => $p->id, 'external_id' => 'CA9', 'user_id' => $owner->id, 'direction' => 'out', 'started_at' => now(), 'recording_url' => 'https://api.twilio.com/rec/RE9.mp3']);
        Http::fake(['api.twilio.com/*' => Http::response('ID3audio', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->actingAs($owner)->get(route('calls.recording', $call))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ACx:twtok')));

        $this->actingAs(User::factory()->create())->get(route('calls.recording', $call))->assertNotFound();

        // Anti-SSRF : une URL interne fournie par un webhook n'est jamais relayée
        $generic = $this->provider('generic');
        $evil = Call::create(['telephony_provider_id' => $generic->id, 'external_id' => 'e1', 'user_id' => $owner->id, 'direction' => 'in', 'started_at' => now(), 'recording_url' => 'http://127.0.0.1:6379/']);
        $this->actingAs($owner)->get(route('calls.recording', $evil))->assertNotFound();
    }

    public function test_sync_error_is_recorded_and_command_respects_interval(): void
    {
        $p = $this->provider('ringover', ['api_key' => 'rk']);
        Http::fake(['*' => Http::response(['message' => 'Invalid API key'], 401)]);

        $this->artisan('telephony:sync', ['--now' => true, '--provider' => $p->id])->assertFailed();
        $p->refresh();
        $this->assertSame('error', $p->last_sync_status);
        $this->assertStringContainsString('401', $p->last_error);

        // Pas encore dû (intervalle de 5 min) : rien n'est lancé
        $this->assertFalse(app(CallSynchronizer::class)->isDue($p));
    }
}
