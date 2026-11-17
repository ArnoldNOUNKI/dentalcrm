<?php

namespace Tests\Feature;

use App\Enums\CallOutcome;
use App\Enums\LeadStatus;
use App\Filament\Pages\CallQueue;
use App\Filament\Pages\Pipeline;
use App\Filament\Resources\LeadResource;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render_for_admin(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('role', 'admin')->first();
        $lead = Lead::has('collections')->first();
        $call = $lead->calls()->first() ?? Call::first();

        $this->actingAs($admin);

        foreach (['/', '/leads', '/leads/create', "/leads/{$lead->id}", "/leads/{$lead->id}/edit", '/pipeline', '/call-queue',
            '/calls', "/calls/{$call->id}", '/tasks', '/collections', '/collections/create', '/collections/'.$lead->collections->first()->id.'/edit',
            '/metal-prices', '/users', '/users/create'] as $url) {
            $r = $this->get($url);
            if ($r->status() !== 200) {
                fwrite(STDERR, "FAIL URL: $url\n");
            } $r->assertOk();
        }
    }

    public function test_commercial_only_sees_own_leads(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = Lead::factory()->create(['assigned_to' => $me->id]);
        $theirs = Lead::factory()->create(['assigned_to' => $other->id]);

        $this->actingAs($me);
        $this->get("/leads/{$mine->id}")->assertOk();
        $this->get("/leads/{$theirs->id}")->assertNotFound();
        $this->get('/users')->assertForbidden();
    }

    public function test_qualify_call_action_from_lead_page(): void
    {
        $me = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $me->id, 'status' => LeadStatus::AAppeler]);
        $this->actingAs($me);

        Livewire::test(LeadResource\Pages\ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('qualifierAppel', ['outcome' => CallOutcome::KitDemande->value, 'agent_notes' => 'OK pour un kit', 'ai' => false])
            ->assertHasNoActionErrors();

        $lead->refresh();
        $this->assertSame(LeadStatus::Interesse, $lead->status);
        $this->assertSame(1, $lead->call_attempts);
        $this->assertSame(1, $lead->calls()->count());
        $this->assertTrue($lead->tasks()->where('title', 'Envoyer le kit de collecte')->exists());
    }

    public function test_call_queue_flow(): void
    {
        $me = User::factory()->create();
        $first = Lead::factory()->create(['assigned_to' => $me->id, 'score' => 90]);
        $second = Lead::factory()->create(['assigned_to' => $me->id, 'score' => 20]);
        $this->actingAs($me);

        Livewire::test(CallQueue::class)
            ->assertSet('leadId', $first->id)
            ->call('outcome', CallOutcome::PasDeReponse->value)
            ->assertSet('leadId', $second->id)
            ->call('skip')
            ->assertSet('leadId', null);

        $first->refresh();
        $this->assertSame(LeadStatus::Injoignable, $first->status);
        $this->assertTrue($first->next_action_at->isFuture()); // re-tentative planifiée → sort de la file
    }

    public function test_pipeline_drag_and_drop(): void
    {
        $me = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $me->id, 'status' => LeadStatus::AAppeler]);
        $this->actingAs($me);

        Livewire::test(Pipeline::class)->call('moveLead', $lead->id, LeadStatus::Interesse->value);

        $this->assertSame(LeadStatus::Interesse, $lead->fresh()->status);
        $this->assertSame(1, $lead->activities()->where('type', 'status_change')->count());
    }

    public function test_list_tabs_and_filters_really_filter(): void
    {
        $me = User::factory()->create();
        $toCall = Lead::factory()->create(['assigned_to' => $me->id, 'status' => LeadStatus::AAppeler, 'score' => 30]);
        $later = Lead::factory()->create(['assigned_to' => $me->id, 'status' => LeadStatus::ARappeler, 'next_action_at' => now()->addDays(2), 'score' => 85]);
        $lost = Lead::factory()->create(['assigned_to' => $me->id, 'status' => LeadStatus::NonInteresse]);
        $this->actingAs($me);

        Livewire::test(LeadResource\Pages\ListLeads::class)
            ->loadTable() // le tableau est en chargement différé
            ->set('activeTab', 'a_appeler')
            ->assertCanSeeTableRecords([$toCall])
            ->assertCanNotSeeTableRecords([$later, $lost])
            ->set('activeTab', 'perdus')
            ->assertCanSeeTableRecords([$lost])
            ->assertCanNotSeeTableRecords([$toCall, $later])
            ->set('activeTab', 'tous')
            ->filterTable('score_min', ['min' => 80])
            ->assertCanSeeTableRecords([$later])
            ->assertCanNotSeeTableRecords([$toCall]);
    }
}
