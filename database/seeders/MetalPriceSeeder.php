<?php

namespace Database\Seeders;

use App\Models\MetalPrice;
use Illuminate\Database\Seeder;

/**
 * Cours indicatifs (€/g de métal fin) à mettre à jour dans « Collectes > Cours des métaux ».
 */
class MetalPriceSeeder extends Seeder
{
    public function run(): void
    {
        $prices = ['au' => 114.00, 'pt' => 45.00, 'pd' => 36.00, 'ag' => 1.45];

        foreach (MetalPrice::METALS as $metal => $label) {
            MetalPrice::firstOrCreate(
                ['metal' => $metal],
                ['label' => $label, 'price_per_gram' => $prices[$metal], 'quoted_at' => now()],
            );
        }
    }
}
