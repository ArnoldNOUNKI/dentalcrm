<?php

namespace App\Models;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\LeadType;
use App\Enums\TaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => LeadType::class,
            'status' => LeadStatus::class,
            'temperature' => LeadTemperature::class,
            'tags' => 'array',
            'ai_insights' => 'array',
            'do_not_call' => 'boolean',
            'assigned_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'next_action_at' => 'datetime',
            'ai_scored_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- Relations

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('occurred_at');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class)->latest('started_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('due_at');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class)->latest();
    }

    // ---------------------------------------------------------------- Accesseurs

    public function getContactNameAttribute(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->contact_civility, $this->contact_first_name, $this->contact_last_name])));

        return $name !== '' ? $name : null;
    }

    /** Meilleur numéro à composer (fixe du cabinet en priorité). */
    public function getDialNumberAttribute(): ?string
    {
        return $this->phone_normalized ?: $this->mobile_normalized;
    }

    // ---------------------------------------------------------------- Scopes

    /** Restreint aux leads visibles par l'utilisateur connecté. */
    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user ??= Auth::user();

        if (! $user || $user->canSeeAllLeads()) {
            return $query;
        }

        return $query->where('assigned_to', $user->id);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, LeadStatus::open()));
    }

    /**
     * File d'appels : leads appelables, rappels échus d'abord, puis leads chauds, puis les plus anciens.
     */
    public function scopeCallQueue(Builder $query): Builder
    {
        return $query
            ->where('do_not_call', false)
            ->whereIn('status', array_map(fn ($s) => $s->value, LeadStatus::callable()))
            ->where(fn (Builder $q) => $q->whereNotNull('phone_normalized')->orWhereNotNull('mobile_normalized'))
            ->where(fn (Builder $q) => $q->whereNull('next_action_at')->orWhere('next_action_at', '<=', now()))
            ->orderByRaw('CASE WHEN next_action_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('next_action_at')
            ->orderByDesc('score')
            ->orderBy('call_attempts')
            ->orderBy('created_at');
    }

    // ---------------------------------------------------------------- Actions métier

    public function logActivity(ActivityType $type, string $subject, ?string $body = null, array $meta = [], ?int $userId = null): Activity
    {
        return $this->activities()->create([
            'user_id' => $userId ?? Auth::id(),
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }

    public function changeStatus(LeadStatus $status, ?string $comment = null): void
    {
        if ($this->status === $status) {
            return;
        }

        $old = $this->status;
        $this->status = $status;
        $this->saveQuietly();

        $this->logActivity(
            ActivityType::StatusChange,
            sprintf('Statut : %s → %s', $old?->getLabel() ?? '—', $status->getLabel()),
            $comment,
            ['from' => $old?->value, 'to' => $status->value],
        );
    }

    public function scheduleFollowUp(\DateTimeInterface $at, string $title, ?int $userId = null, TaskType $type = TaskType::Rappel): Task
    {
        $this->forceFill(['next_action_at' => $at, 'next_action' => $title])->saveQuietly();

        $task = $this->tasks()->create([
            'user_id' => $userId ?? $this->assigned_to ?? Auth::id(),
            'created_by' => Auth::id(),
            'title' => $title,
            'type' => $type,
            'due_at' => $at,
        ]);

        $this->logActivity(ActivityType::Task, 'Rappel planifié : '.$title, null, ['due_at' => $at->format(DATE_ATOM)]);

        return $task;
    }
}
