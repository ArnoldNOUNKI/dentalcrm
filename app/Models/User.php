<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\PhoneNormalizer;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'phone', 'ringover_number', 'ringover_user_id',
        'is_active', 'receives_leads', 'daily_call_target',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'receives_leads' => 'boolean',
        ];
    }

    public function setRingoverNumberAttribute(?string $value): void
    {
        $this->attributes['ringover_number'] = PhoneNormalizer::normalize($value);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Admin et managers voient tous les leads ; les commerciaux uniquement les leurs. */
    public function canSeeAllLeads(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Manager, UserRole::Logistique], true);
    }

    public function canManageTeam(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Manager], true);
    }

    public function scopeSalesAgents(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereIn('role', [UserRole::Commercial->value, UserRole::Manager->value]);
    }
}
