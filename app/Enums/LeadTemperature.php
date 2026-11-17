<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LeadTemperature: string implements HasColor, HasLabel
{
    case Froid = 'froid';
    case Tiede = 'tiede';
    case Chaud = 'chaud';

    public function getLabel(): string
    {
        return match ($this) {
            self::Froid => 'Froid',
            self::Tiede => 'Tiède',
            self::Chaud => 'Chaud',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Froid => 'info',
            self::Tiede => 'warning',
            self::Chaud => 'danger',
        };
    }

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 70 => self::Chaud,
            $score >= 40 => self::Tiede,
            default => self::Froid,
        };
    }
}
