<?php

namespace App\Models;

use App\Services\PhoneNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelephonyAgent extends Model
{
    protected $guarded = ['id'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(TelephonyProvider::class, 'telephony_provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setPhoneNumberAttribute(?string $value): void
    {
        $this->attributes['phone_number'] = PhoneNormalizer::normalize($value) ?? ($value ?: null);
    }
}
