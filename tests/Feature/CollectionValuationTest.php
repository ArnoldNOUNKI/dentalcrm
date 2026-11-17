<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Lead;
use App\Models\MetalPrice;
use Database\Seeders\MetalPriceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionValuationTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_value(): void
    {
        $prices = ['au' => 100.0, 'pt' => 40.0, 'pd' => 30.0, 'ag' => 1.0];

        // 10 g × (50 % × 100 + 10 % × 30) × 85 % = 10 × 53 × 0.85 = 450.50
        $this->assertSame(450.5, Collection::computeValue(['net_weight_g' => 10, 'au_pct' => 50, 'pd_pct' => 10, 'payout_rate' => 85], $prices));
        $this->assertNull(Collection::computeValue(['au_pct' => 50], $prices));
    }

    public function test_value_and_reference_are_set_on_save(): void
    {
        $this->seed(MetalPriceSeeder::class);
        MetalPrice::where('metal', 'au')->update(['price_per_gram' => 100]);
        cache()->forget('metal_prices');

        $c = Collection::create(['lead_id' => Lead::factory()->create()->id, 'net_weight_g' => 20, 'au_pct' => 50, 'payout_rate' => 80]);

        $this->assertEquals(800.00, (float) $c->estimated_value);
        $this->assertMatchesRegularExpression('/^C\d{2}\d{5}$/', $c->fresh()->reference);
    }
}
