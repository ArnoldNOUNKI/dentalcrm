<?php

namespace App\Models;

use App\Services\Telephony\Drivers\TelephonyDriver;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Service de téléphonie connecté au CRM (paramètres de connexion chiffrés en base).
 */
class TelephonyProvider extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    /** Valeurs par défaut (identiques à la migration) pour les instances non rechargées. */
    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'sync_enabled' => true,
        'sync_interval_minutes' => 5,
        'last_sync_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'synced_until' => 'datetime',
            'last_webhook_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TelephonyProvider $p) {
            $p->webhook_token ??= Str::random(40);
        });

        // Un seul service par défaut pour le click-to-call
        static::saved(function (TelephonyProvider $p) {
            if ($p->is_default && $p->wasChanged('is_default')) {
                static::whereKeyNot($p->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function agents(): HasMany
    {
        return $this->hasMany(TelephonyAgent::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function driver(): TelephonyDriver
    {
        return app(TelephonyManager::class)->driver($this->driver);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        $v = Arr::get($this->credentials ?? [], $key);

        return $v === null || $v === '' ? $default : $v;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $v = Arr::get($this->settings ?? [], $key);

        return $v === null || $v === '' ? $default : $v;
    }

    public function webhookUrl(): string
    {
        return url('/webhooks/telephony/'.$this->webhook_token);
    }

    public function agentFor(?User $user): ?TelephonyAgent
    {
        return $user ? $this->agents()->where('user_id', $user->id)->first() : null;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Service utilisé pour le click-to-call : celui par défaut, sinon le premier actif qui le permet. */
    public static function forClickToCall(): ?self
    {
        $manager = app(TelephonyManager::class);

        return static::active()->orderByDesc('is_default')->orderBy('id')->get()
            ->first(fn (self $p) => $manager->has($p->driver) && $p->driver()->supportsClickToCall($p));
    }

    /** Fournisseur Ringover créé à partir des anciennes variables RINGOVER_* (compatibilité). */
    public static function ringoverFromEnv(): ?self
    {
        $key = config('services.ringover.api_key');
        $token = config('services.ringover.webhook_token');

        if (! $key && ! $token) {
            return null;
        }

        $existing = static::where('driver', 'ringover')->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        return static::create([
            'driver' => 'ringover',
            'name' => 'Ringover',
            'is_default' => ! static::where('is_default', true)->exists(),
            'credentials' => array_filter([
                'api_key' => $key,
                'region' => str_contains((string) config('services.ringover.base_url'), 'api-us') ? 'us' : 'eu',
            ]),
            'webhook_token' => $token ?: Str::random(40),
            'sync_enabled' => (bool) config('services.ringover.sync_enabled', true),
        ]);
    }
}
