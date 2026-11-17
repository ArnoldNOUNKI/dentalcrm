<?php

namespace App\Models;

use App\Enums\CallOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Call extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'outcome' => CallOutcome::class,
            'is_answered' => 'boolean',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'ai_extracted' => 'array',
            'ai_processed_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user ??= Auth::user();

        if (! $user || $user->canSeeAllLeads()) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    public function getDurationLabelAttribute(): string
    {
        return sprintf('%d:%02d', intdiv($this->duration, 60), $this->duration % 60);
    }

    /** Assez de matière pour que l'IA produise un résumé utile. */
    public function hasContentForAi(): bool
    {
        return filled($this->transcript) || filled($this->agent_notes);
    }
}
