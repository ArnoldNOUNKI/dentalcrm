<?php

namespace App\Services\Telephony;

use App\Services\Telephony\Drivers\AircallDriver;
use App\Services\Telephony\Drivers\AsteriskDriver;
use App\Services\Telephony\Drivers\GenericWebhookDriver;
use App\Services\Telephony\Drivers\RingoverDriver;
use App\Services\Telephony\Drivers\TelephonyDriver;
use App\Services\Telephony\Drivers\TwilioDriver;
use InvalidArgumentException;

/**
 * Registre des connecteurs de téléphonie disponibles.
 */
class TelephonyManager
{
    /** @var array<string, class-string<TelephonyDriver>> */
    protected array $drivers = [
        'ringover' => RingoverDriver::class,
        'aircall' => AircallDriver::class,
        'twilio' => TwilioDriver::class,
        'asterisk' => AsteriskDriver::class,
        'generic' => GenericWebhookDriver::class,
    ];

    public function has(?string $key): bool
    {
        return $key !== null && isset($this->drivers[$key]);
    }

    public function driver(string $key): TelephonyDriver
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Service de téléphonie inconnu : {$key}");
        }

        return app($this->drivers[$key]);
    }

    /** @return array<string, string> clé => libellé */
    public function options(): array
    {
        return collect($this->drivers)->map(fn ($class) => app($class)->label())->all();
    }
}
