<?php

namespace App\Services\Telephony;

use App\Enums\CallStatus;
use App\Services\PhoneNormalizer;
use Illuminate\Support\Carbon;

/**
 * Appel au format commun, produit par chaque connecteur (webhook ou synchronisation).
 */
class NormalizedCall
{
    public function __construct(
        public string $externalId,
        public string $direction,                // in | out
        public CallStatus $status,
        public ?string $fromNumber = null,
        public ?string $toNumber = null,
        public ?string $externalNumber = null,   // numéro du correspondant (prospect)
        public ?Carbon $startedAt = null,
        public ?Carbon $answeredAt = null,
        public ?Carbon $endedAt = null,
        public int $duration = 0,                // durée de conversation (s)
        public ?int $ringDuration = null,        // attente / sonnerie (s)
        public ?string $recordingUrl = null,
        public ?string $voicemailUrl = null,
        public ?string $transcript = null,
        public ?string $missedReason = null,
        public ?string $hangupBy = null,
        public ?string $agentExternalId = null,
        public ?string $agentEmail = null,
        public ?string $agentNumber = null,
        public ?string $agentExtension = null,
        public ?int $agentUserId = null,         // utilisateur CRM connu (ex. click-to-call Twilio)
        public bool $isFinal = true,
        public array $raw = [],
    ) {
        $this->fromNumber = PhoneNormalizer::normalize($this->fromNumber) ?? $this->fromNumber;
        $this->toNumber = PhoneNormalizer::normalize($this->toNumber) ?? $this->toNumber;
        $this->externalNumber = PhoneNormalizer::normalize($this->externalNumber ?? ($direction === 'in' ? $this->fromNumber : $this->toNumber));
        $this->agentNumber = PhoneNormalizer::normalize($this->agentNumber);
    }

    public static function date(mixed $value, ?string $timezone = null): ?Carbon
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        try {
            $date = is_numeric($value)
                ? Carbon::createFromTimestamp((int) ($value > 9999999999 ? $value / 1000 : $value))
                : Carbon::parse($value, $timezone);

            return $date->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** Sérialisation pour passage en file d'attente. */
    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['status'] = $this->status->value;
        foreach (['startedAt', 'answeredAt', 'endedAt'] as $k) {
            $data[$k] = $this->{$k}?->toIso8601String();
        }

        return $data;
    }

    public static function fromArray(array $d): self
    {
        $call = new self(
            externalId: $d['externalId'], direction: $d['direction'], status: CallStatus::from($d['status']),
            fromNumber: $d['fromNumber'], toNumber: $d['toNumber'], externalNumber: $d['externalNumber'],
            startedAt: self::date($d['startedAt']), answeredAt: self::date($d['answeredAt']), endedAt: self::date($d['endedAt']),
            duration: (int) $d['duration'], ringDuration: $d['ringDuration'], recordingUrl: $d['recordingUrl'],
            voicemailUrl: $d['voicemailUrl'], transcript: $d['transcript'], missedReason: $d['missedReason'],
            hangupBy: $d['hangupBy'], agentExternalId: $d['agentExternalId'], agentEmail: $d['agentEmail'],
            agentNumber: $d['agentNumber'], agentExtension: $d['agentExtension'], agentUserId: $d['agentUserId'],
            isFinal: (bool) $d['isFinal'], raw: $d['raw'] ?? [],
        );

        return $call;
    }
}
