<?php

namespace App\Filament\Widgets\Concerns;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

trait ScopedByDashboardFilters
{
    protected function periodStart(): Carbon
    {
        $days = (int) ($this->filters['period'] ?? 30);

        return $days <= 1 ? today() : now()->subDays($days)->startOfDay();
    }

    /** Utilisateur ciblé : filtre manager, sinon soi-même pour un commercial, sinon null (toute l'équipe). */
    protected function scopedUser(): ?User
    {
        $user = Auth::user();

        if (! $user->canSeeAllLeads()) {
            return $user;
        }

        return ! empty($this->filters['user_id']) ? User::find($this->filters['user_id']) : null;
    }
}
