<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\LeadIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'sk-test']);
    }

    private function fakeTool(array $input): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'x', 'input' => $input]],
            'stop_reason' => 'tool_use',
        ])]);
    }

    public function test_score_lead_persists_insights(): void
    {
        $this->fakeTool([
            'score' => 82, 'type' => 'laboratoire', 'summary' => 'Gros labo.', 'pitch' => 'Bonjour…',
            'objections' => ['A → B'], 'next_action' => 'Appeler le gérant', 'best_time_to_call' => '12h-14h',
        ]);

        $lead = Lead::factory()->create(['type' => 'autre']);
        app(LeadIntelligence::class)->scoreLead($lead);

        $lead->refresh();
        $this->assertSame(82, $lead->score);
        $this->assertSame(LeadTemperature::Chaud, $lead->temperature);
        $this->assertSame('laboratoire', $lead->type->value);
        $this->assertSame(['A → B'], $lead->ai_insights['objections']);

        Http::assertSent(function (Request $r) {
            return $r->hasHeader('x-api-key', 'sk-test')
                && $r['tool_choice']['type'] === 'tool'
                && $r['model'] === config('services.anthropic.model_fast');
        });
    }

    public function test_call_summary_updates_lead_and_creates_follow_up(): void
    {
        $agent = User::factory()->create();
        $lead = Lead::factory()->create(['status' => LeadStatus::AAppeler, 'assigned_to' => $agent->id]);
        $call = $lead->calls()->create([
            'user_id' => $agent->id, 'direction' => 'out', 'started_at' => now(), 'ended_at' => now(), 'duration' => 120,
            'is_answered' => true, 'agent_notes' => 'Dr OK pour être rappelé jeudi 11h, travaille avec Comptoir X, ~50 g/an',
        ]);

        $followUp = now()->addDays(3)->setTime(11, 0);
        $this->fakeTool([
            'summary' => '• Intéressé', 'outcome' => 'rappel', 'sentiment' => 'positif', 'interest_score' => 70,
            'next_step' => 'Rappeler le Dr jeudi', 'follow_up_at' => $followUp->toIso8601String(),
            'current_buyer' => 'Comptoir X', 'estimated_grams_year' => 50, 'coaching_tip' => 'Bien.',
        ]);

        app(LeadIntelligence::class)->summarizeCall($call);

        $call->refresh();
        $lead->refresh();
        $this->assertSame(CallOutcome::Rappel, $call->outcome);
        $this->assertSame('positif', $call->ai_sentiment);
        $this->assertSame(LeadStatus::ARappeler, $lead->status);
        $this->assertSame('Comptoir X', $lead->current_buyer);
        $this->assertSame(50, $lead->estimated_grams_year);
        $this->assertSame(70, $lead->score);
        $this->assertSame(1, $lead->tasks()->count());
        $this->assertTrue($lead->next_action_at->equalTo($followUp));
    }

    public function test_api_error_is_reported(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad model']], 400)]);

        $this->expectExceptionMessage('bad model');
        app(LeadIntelligence::class)->scoreLead(Lead::factory()->create());
    }
}
