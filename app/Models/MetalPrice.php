<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class MetalPrice extends Model
{
    public const METALS = [
        'au' => 'Or',
        'pt' => 'Platine',
        'pd' => 'Palladium',
        'ag' => 'Argent',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_per_gram' => 'decimal:3',
            'quoted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('metal_prices'));
    }

    /** @return array<string, float> prix €/g de métal fin, indexés par métal */
    public static function current(): array
    {
        return Cache::remember('metal_prices', 300, fn () => static::query()
            ->pluck('price_per_gram', 'metal')
            ->map(fn ($p) => (float) $p)
            ->all());
    }
}
