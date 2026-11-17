<?php

namespace App\Models;

use App\Enums\CollectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Collecte de déchets métalliques dentaires (or, platine, palladium, argent).
 *
 * Valorisation = poids net × Σ(titre % × cours €/g) × taux de reversement.
 */
class Collection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CollectionStatus::class,
            'items' => 'array',
            'price_snapshot' => 'array',
            'kit_sent_at' => 'date',
            'pickup_date' => 'date',
            'received_at' => 'date',
            'offer_sent_at' => 'date',
            'paid_at' => 'date',
            'gross_weight_g' => 'decimal:2',
            'net_weight_g' => 'decimal:2',
            'au_pct' => 'decimal:2',
            'pt_pct' => 'decimal:2',
            'pd_pct' => 'decimal:2',
            'ag_pct' => 'decimal:2',
            'payout_rate' => 'decimal:2',
            'estimated_value' => 'decimal:2',
            'offer_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Collection $c) {
            $c->reference ??= 'TMP-'.Str::random(14);
        });

        static::created(function (Collection $c) {
            if (str_starts_with($c->reference, 'TMP-')) {
                $c->reference = 'C'.now()->format('y').str_pad((string) $c->id, 5, '0', STR_PAD_LEFT);
                $c->saveQuietly();
            }
        });

        static::saving(function (Collection $c) {
            if ($c->isDirty(['net_weight_g', 'gross_weight_g', 'au_pct', 'pt_pct', 'pd_pct', 'ag_pct', 'payout_rate'])) {
                $prices = MetalPrice::current();
                $c->estimated_value = static::computeValue($c->only(['net_weight_g', 'gross_weight_g', 'au_pct', 'pt_pct', 'pd_pct', 'ag_pct', 'payout_rate']), $prices);
                $c->price_snapshot = $prices + ['at' => now()->toDateTimeString()];
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, float>  $prices
     */
    public static function computeValue(array $data, array $prices): ?float
    {
        $weight = (float) ($data['net_weight_g'] ?? 0) ?: (float) ($data['gross_weight_g'] ?? 0);

        if ($weight <= 0) {
            return null;
        }

        $metalValuePerGram = 0.0;
        foreach (array_keys(MetalPrice::METALS) as $metal) {
            $pct = (float) ($data[$metal.'_pct'] ?? 0);
            $metalValuePerGram += ($pct / 100) * ($prices[$metal] ?? 0);
        }

        $rate = (float) ($data['payout_rate'] ?? 85) / 100;

        return round($weight * $metalValuePerGram * $rate, 2);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user ??= Auth::user();

        if (! $user || $user->canSeeAllLeads()) {
            return $query;
        }

        return $query->whereHas('lead', fn (Builder $q) => $q->where('assigned_to', $user->id));
    }
}
