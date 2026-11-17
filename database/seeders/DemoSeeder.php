<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\CallOutcome;
use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\LeadType;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\Lead;
use App\Models\User;
use Faker\Factory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Lead::count() > 0) {
            return;
        }

        $manager = User::firstOrCreate(['email' => 'manager@demo.fr'], ['name' => 'Sophie Manager', 'password' => 'demo-password', 'role' => UserRole::Manager, 'receives_leads' => false]);
        $agents = collect([
            ['email' => 'karim@demo.fr', 'name' => 'Karim Commercial', 'ringover_number' => '+33387000001'],
            ['email' => 'julie@demo.fr', 'name' => 'Julie Commerciale', 'ringover_number' => '+33387000002'],
        ])->map(fn ($a) => User::firstOrCreate(['email' => $a['email']], $a + ['password' => 'demo-password', 'role' => UserRole::Commercial]));
        User::firstOrCreate(['email' => 'labo@demo.fr'], ['name' => 'Marc Logistique', 'password' => 'demo-password', 'role' => UserRole::Logistique, 'receives_leads' => false]);

        $faker = Factory::create('fr_FR');
        $cities = [['57600', 'Forbach'], ['57000', 'Metz'], ['67000', 'Strasbourg'], ['54000', 'Nancy'], ['57100', 'Thionville'], ['57200', 'Sarreguemines'], ['68100', 'Mulhouse'], ['51100', 'Reims'], ['88000', 'Épinal'], ['55100', 'Verdun']];
        $statuses = [LeadStatus::Nouveau, LeadStatus::AAppeler, LeadStatus::AAppeler, LeadStatus::Injoignable, LeadStatus::ARappeler, LeadStatus::Interesse, LeadStatus::KitEnvoye, LeadStatus::Client, LeadStatus::NonInteresse];

        for ($i = 0; $i < 60; $i++) {
            $type = $faker->randomElement([LeadType::Cabinet, LeadType::Cabinet, LeadType::Cabinet, LeadType::Laboratoire, LeadType::Centre, LeadType::Orthodontiste]);
            [$cp, $city] = $faker->randomElement($cities);
            $last = $faker->lastName();
            $status = $faker->randomElement($statuses);
            $score = $faker->numberBetween(15, 95);
            $agent = $agents->random();

            $lead = Lead::create([
                'company_name' => match ($type) {
                    LeadType::Laboratoire => 'Laboratoire '.$faker->randomElement(['Dentaire', 'de Prothèse', 'Prothèse Dentaire']).' '.$last,
                    LeadType::Centre => 'Centre Dentaire '.$city.' '.$faker->randomElement(['Gare', 'Centre', 'Santé', 'Plus']),
                    LeadType::Orthodontiste => 'Cabinet d\'orthodontie Dr '.$last,
                    default => 'Cabinet Dentaire Dr '.$last,
                },
                'type' => $type,
                'practitioners_count' => $type === LeadType::Centre ? $faker->numberBetween(3, 12) : $faker->numberBetween(1, 3),
                'contact_civility' => 'Dr',
                'contact_first_name' => $faker->firstName(),
                'contact_last_name' => $last,
                'contact_role' => $type === LeadType::Laboratoire ? 'Prothésiste gérant' : 'Chirurgien-dentiste',
                'phone' => '03'.$faker->numerify('########'),
                'email' => $faker->boolean(60) ? strtolower(Str::ascii($last)).$i.'@'.$faker->freeEmailDomain() : null,
                'address' => $faker->streetAddress(),
                'postal_code' => $cp,
                'city' => $city,
                'status' => $status,
                'source' => $faker->randomElement(['Fichier Ordre 57', 'Salon ADF', 'Site web', 'Recommandation']),
                'assigned_to' => $agent->id,
                'score' => $score,
                'temperature' => LeadTemperature::fromScore($score),
                'call_attempts' => $status === LeadStatus::Nouveau ? 0 : $faker->numberBetween(1, 5),
                'last_contacted_at' => $status === LeadStatus::Nouveau ? null : now()->subDays($faker->numberBetween(0, 20)),
                'next_action_at' => in_array($status, [LeadStatus::ARappeler, LeadStatus::Interesse], true) ? now()->addHours($faker->numberBetween(-24, 72)) : null,
                'next_action' => in_array($status, [LeadStatus::ARappeler, LeadStatus::Interesse], true) ? 'Rappeler le praticien' : null,
                'current_buyer' => $faker->boolean(30) ? $faker->randomElement(['Comptoir Lyonnais', 'Récupérateur local', 'Fournisseur de matériel']) : null,
                'ai_summary' => 'Données de démonstration : '.$type->getLabel().' à '.$city.'.',
                'ai_pitch' => "Bonjour, je suis X de la société de collecte dentaire. Nous récupérons gratuitement vos anciennes couronnes, bridges et chutes d'alliage, et nous vous les rachetons au cours du jour après analyse. Auriez-vous deux minutes ?",
                'ai_insights' => ['objections' => ['« On a déjà quelqu\'un » → proposer une comparaison sur le prochain lot', '« Pas assez de volume » → le kit est gratuit et sans minimum'], 'best_time_to_call' => '12h15 - 13h45', 'next_action' => 'Appeler et proposer un kit'],
                'ai_scored_at' => now(),
            ]);

            $lead->logActivity(ActivityType::Import, 'Importé (démo)', userId: $agent->id);

            if ($lead->call_attempts > 0) {
                for ($c = 0; $c < $lead->call_attempts; $c++) {
                    $answered = $faker->boolean(55);
                    $lead->calls()->create([
                        'user_id' => $agent->id,
                        'direction' => 'out',
                        'from_number' => $agent->ringover_number,
                        'to_number' => $lead->phone_normalized,
                        'is_answered' => $answered,
                        'duration' => $answered ? $faker->numberBetween(30, 420) : 0,
                        'started_at' => now()->subDays($faker->numberBetween(0, 25))->setTime($faker->numberBetween(8, 18), $faker->numberBetween(0, 59)),
                        'outcome' => $answered ? $faker->randomElement([CallOutcome::Repondu, CallOutcome::Barrage, CallOutcome::Interesse, CallOutcome::Rappel]) : CallOutcome::PasDeReponse,
                        'status' => $answered ? 'answered' : 'no_answer',
                        'external_number' => $lead->phone_normalized,
                    ]);
                }
            }

            if (in_array($status, [LeadStatus::KitEnvoye, LeadStatus::Client], true)) {
                $cStatus = $status === LeadStatus::KitEnvoye ? CollectionStatus::KitEnvoye : $faker->randomElement([CollectionStatus::EnAnalyse, CollectionStatus::OffreEnvoyee, CollectionStatus::Payee]);
                $weight = $faker->randomFloat(2, 8, 120);
                $collection = Collection::create([
                    'lead_id' => $lead->id,
                    'user_id' => $agent->id,
                    'status' => $cStatus,
                    'kit_sent_at' => now()->subDays(20),
                    'received_at' => $cStatus !== CollectionStatus::KitEnvoye ? now()->subDays(10) : null,
                    'items' => [['type' => 'couronnes', 'quantity' => $faker->numberBetween(5, 40)], ['type' => 'alliages', 'quantity' => null]],
                    'gross_weight_g' => $cStatus !== CollectionStatus::KitEnvoye ? $weight : null,
                    'net_weight_g' => $cStatus !== CollectionStatus::KitEnvoye ? round($weight * 0.85, 2) : null,
                    'au_pct' => $cStatus !== CollectionStatus::KitEnvoye ? $faker->randomFloat(2, 35, 72) : null,
                    'pt_pct' => $cStatus !== CollectionStatus::KitEnvoye ? $faker->randomFloat(2, 0, 8) : null,
                    'pd_pct' => $cStatus !== CollectionStatus::KitEnvoye ? $faker->randomFloat(2, 2, 25) : null,
                    'ag_pct' => $cStatus !== CollectionStatus::KitEnvoye ? $faker->randomFloat(2, 1, 15) : null,
                ]);

                if ($cStatus === CollectionStatus::Payee) {
                    $collection->update(['offer_amount' => $collection->estimated_value, 'paid_amount' => $collection->estimated_value, 'paid_at' => now()->subDays(3), 'payment_method' => 'virement']);
                }
            }
        }

        // Quelques rappels
        Lead::whereNotNull('next_action_at')->get()->each(fn (Lead $l) => $l->tasks()->create([
            'user_id' => $l->assigned_to, 'title' => $l->next_action, 'type' => 'rappel', 'due_at' => $l->next_action_at,
        ]));
    }
}
