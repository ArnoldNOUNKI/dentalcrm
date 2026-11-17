<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Filament\Imports\LeadImporter;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImportTest extends TestCase
{
    use RefreshDatabase;

    private function importer(User $user, array $options = []): LeadImporter
    {
        $import = Import::create([
            'user_id' => $user->id, 'file_name' => 'leads.csv', 'file_path' => 'leads.csv',
            'importer' => LeadImporter::class, 'total_rows' => 3,
        ]);

        $map = [
            'company_name' => 'Nom', 'type' => 'Activité', 'phone' => 'Tél', 'email' => 'Email',
            'postal_code' => 'CP', 'city' => 'Ville', 'contact_last_name' => 'Praticien',
        ];

        return new LeadImporter($import, $map, $options + ['duplicates' => 'complete']);
    }

    public function test_import_normalizes_assigns_and_dedupes(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();

        $importer = $this->importer($admin);

        $importer(['Nom' => 'Labo Prothèse Martin', 'Activité' => 'laboratoire de prothese', 'Tél' => '03 87 12 34 56', 'Email' => 'Contact@Labo.FR', 'CP' => '57600', 'Ville' => 'Forbach', 'Praticien' => 'Martin']);
        $importer(['Nom' => 'Cabinet Dr Durand', 'Activité' => 'Chirurgien-dentiste', 'Tél' => '387654321', 'Email' => '', 'CP' => '7000', 'Ville' => 'Privas', 'Praticien' => '']);
        // doublon (même téléphone, autre format) : complète l'email vide sans créer de lead
        $importer(['Nom' => 'Cabinet Durand (doublon)', 'Activité' => '', 'Tél' => '+33 3 87 65 43 21', 'Email' => 'durand@cabinet.fr', 'CP' => '', 'Ville' => '', 'Praticien' => '']);

        $this->assertSame(2, Lead::count());

        $labo = Lead::where('company_name', 'Labo Prothèse Martin')->first();
        $this->assertSame(LeadType::Laboratoire, $labo->type);
        $this->assertSame('+33387123456', $labo->phone_normalized);
        $this->assertSame('contact@labo.fr', $labo->email);
        $this->assertSame('57', $labo->department);
        $this->assertSame(LeadStatus::AAppeler, $labo->status);
        $this->assertNotNull($labo->assigned_to);

        $durand = Lead::where('phone_normalized', '+33387654321')->first();
        $this->assertSame('Cabinet Dr Durand', $durand->company_name); // pas écrasé
        $this->assertSame('durand@cabinet.fr', $durand->email);         // complété
        $this->assertSame('07000', $durand->postal_code);

        // équilibrage : chaque commercial a reçu un lead
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Lead::pluck('assigned_to')->all());
    }

    public function test_forced_assignee_and_skip_mode(): void
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->create();
        User::factory()->create();

        $importer = $this->importer($admin, ['assign_to' => $agent->id, 'duplicates' => 'skip']);
        $importer(['Nom' => 'Centre Dentaire Metz', 'Activité' => '', 'Tél' => '0387000099', 'Email' => '', 'CP' => '57000', 'Ville' => 'Metz', 'Praticien' => '']);
        $importer(['Nom' => 'Autre nom', 'Activité' => '', 'Tél' => '0387000099', 'Email' => '', 'CP' => '', 'Ville' => '', 'Praticien' => '']);

        $this->assertSame(1, Lead::count());
        $lead = Lead::first();
        $this->assertSame($agent->id, $lead->assigned_to);
        $this->assertSame(LeadType::Centre, $lead->type); // deviné depuis le nom
    }
}
