<?php

namespace App\Observers;

use App\Enums\LeadType;
use App\Models\Lead;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Str;

class LeadObserver
{
    public function creating(Lead $lead): void
    {
        $lead->reference ??= 'TMP-'.Str::random(14);
        $lead->type ??= LeadType::Cabinet;
    }

    public function created(Lead $lead): void
    {
        if (str_starts_with($lead->reference, 'TMP-')) {
            $lead->reference = 'L'.now()->format('y').str_pad((string) $lead->id, 6, '0', STR_PAD_LEFT);
            $lead->saveQuietly();
        }
    }

    public function saving(Lead $lead): void
    {
        if ($lead->isDirty('phone')) {
            $lead->phone_normalized = PhoneNormalizer::normalize($lead->phone, $lead->country ?: 'FR');
        }

        if ($lead->isDirty('mobile')) {
            $lead->mobile_normalized = PhoneNormalizer::normalize($lead->mobile, $lead->country ?: 'FR');
        }

        if ($lead->isDirty('email') && $lead->email) {
            $lead->email = mb_strtolower(trim($lead->email));
        }

        if ($lead->isDirty('postal_code') && $lead->postal_code && ($lead->country ?: 'FR') === 'FR') {
            $cp = str_pad(preg_replace('/\D/', '', $lead->postal_code), 5, '0', STR_PAD_LEFT);
            $lead->postal_code = $cp;
            $lead->department = str_starts_with($cp, '20')
                ? ((int) $cp >= 20200 ? '2B' : '2A')
                : (in_array(substr($cp, 0, 2), ['97', '98'], true) ? substr($cp, 0, 3) : substr($cp, 0, 2));
        }

        if ($lead->isDirty('siret') && $lead->siret) {
            $lead->siret = preg_replace('/\D/', '', $lead->siret);
        }

        if ($lead->isDirty('assigned_to') && $lead->assigned_to) {
            $lead->assigned_at = now();
        }
    }
}
