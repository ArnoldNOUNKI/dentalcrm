<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->pending()->where('due_at', '<', now());
    }

    public function complete(): void
    {
        $this->update(['completed_at' => now()]);

        // Si c'était la prochaine action du lead, on la libère
        if ($this->lead && $this->lead->next_action === $this->title) {
            $next = $this->lead->tasks()->pending()->where('id', '!=', $this->id)->orderBy('due_at')->first();
            $this->lead->forceFill([
                'next_action_at' => $next?->due_at,
                'next_action' => $next?->title,
            ])->saveQuietly();
        }
    }
}
