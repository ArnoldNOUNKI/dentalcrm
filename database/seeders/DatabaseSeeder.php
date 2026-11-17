<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            MetalPriceSeeder::class,
        ]);

        // Données de démonstration uniquement hors production
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
