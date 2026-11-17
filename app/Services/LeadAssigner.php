<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Distribution automatique des leads entre commerciaux actifs.
 * Stratégie : le commercial avec le moins de leads ouverts reçoit le suivant (équilibrage de charge).
 */
class LeadAssigner
{
    public function assign(Lead $lead, ?User $user = null, bool $log = true): ?User
    {
        $user ??= $this->pickAgent();

        if (! $user) {
            return null;
        }

        $previous = $lead->assigned_to;

        $lead->forceFill([
            'assigned_to' => $user->id,
            'assigned_at' => now(),
        ])->saveQuietly();

        if ($log && $previous !== $user->id) {
            $lead->logActivity(ActivityType::Assignment, 'Attribué à '.$user->name, null, ['from' => $previous, 'to' => $user->id]);
        }

        return $user;
    }

    public function pickAgent(): ?User
    {
        // Verrou court pour éviter que deux jobs d'import parallèles choisissent le même agent sur la même base
        return Cache::lock('lead-assigner', 5)->block(5, function () {
            return User::query()
                ->salesAgents()
                ->where('receives_leads', true)
                ->withCount(['leads as open_leads_count' => fn ($q) => $q->open()])
                ->orderBy('open_leads_count')
                ->orderBy('id')
                ->first();
        });
    }
}
