<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_name' => 'Cabinet Dentaire Dr '.fake()->lastName(),
            'type' => LeadType::Cabinet,
            'contact_civility' => 'Dr',
            'contact_last_name' => fake()->lastName(),
            'phone' => '03'.fake()->unique()->numerify('########'),
            'postal_code' => '57600',
            'city' => 'Forbach',
            'status' => LeadStatus::AAppeler,
        ];
    }
}
