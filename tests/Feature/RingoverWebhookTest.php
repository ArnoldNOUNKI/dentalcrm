<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RingoverWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_bad_token(): void
    {
        $this->postJson('/webhooks/ringover/wrong', ['event' => 'hangup'])->assertUnauthorized();
    }

    public function test_hangup_creates_call_matched_to_lead_and_agent(): void
    {
        $agent = User::factory()->create(['ringover_user_id' => '777', 'ringover_number' => '03 87 11 22 33']);
        $lead = Lead::factory()->create(['phone' => '03 87 00 00 01', 'status' => LeadStatus::Nouveau]);

        $payload = [
            'resource' => 'call',
            'event' => 'hangup',
            'data' => [
                'call_id' => 'abc-123',
                'direction' => 'outbound',
                'from_number' => '33387112233',
                'to_number' => '33387000001',
                'user' => ['user_id' => 777],
                'start_time' => now()->subMinutes(3)->toIso8601String(),
                'answered_time' => now()->subMinutes(3)->toIso8601String(),
                'end_time' => now()->toIso8601String(),
                'incall_duration' => 154,
                'record' => 'https://cdn.ringover.com/rec/abc.mp3',
            ],
        ];

        $this->postJson('/webhooks/ringover/test-token', $payload)->assertOk();

        $call = Call::firstOrFail();
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame($agent->id, $call->user_id);
        $this->assertTrue($call->is_answered);
        $this->assertSame(154, $call->duration);
        $this->assertNotNull($call->ended_at);

        $lead->refresh();
        $this->assertSame(1, $lead->call_attempts);
        $this->assertNotNull($lead->last_contacted_at);
        $this->assertSame(LeadStatus::AAppeler, $lead->status);
        $this->assertTrue($lead->activities()->where('type', ActivityType::Call)->exists());

        // Le même événement rejoué (retry / sync) ne doit rien dupliquer
        $this->postJson('/webhooks/ringover/test-token', $payload)->assertOk();
        $this->assertSame(1, Call::count());
        $this->assertSame(1, $lead->fresh()->call_attempts);
    }

    public function test_missed_call_flat_payload(): void
    {
        $lead = Lead::factory()->create(['phone' => '0387000002']);

        $this->postJson('/webhooks/ringover/test-token', [
            'event' => 'missed',
            'call_id' => 'zz-9',
            'caller_number' => '+33387000002',
            'receiver_number' => '+33387999999',
            'direction' => 'inbound',
            'timestamp' => now()->toIso8601String(),
        ])->assertOk();

        $call = Call::firstOrFail();
        $this->assertSame('in', $call->direction);
        $this->assertFalse($call->is_answered);
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame(0, $lead->fresh()->call_attempts); // un appel entrant n'est pas une tentative
    }

    public function test_utc_timestamps_are_converted_to_app_timezone(): void
    {
        config(['app.timezone' => 'Europe/Paris']);
        date_default_timezone_set('Europe/Paris');
        Lead::factory()->create(['phone' => '0387000003']);

        $this->postJson('/webhooks/ringover/test-token', ['event' => 'hangup', 'data' => [
            'call_id' => 'tz-1', 'direction' => 'out', 'to_number' => '33387000003',
            'start_time' => '2026-11-16T09:00:00Z', 'end_time' => '2026-11-16T09:02:00Z',
        ]])->assertOk();

        $this->assertSame('2026-11-16 10:00:00', Call::first()->getRawOriginal('started_at'));
        date_default_timezone_set('UTC');
    }
}
