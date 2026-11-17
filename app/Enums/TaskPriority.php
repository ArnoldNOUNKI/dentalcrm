<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaskPriority: string implements HasColor, HasLabel
{
    case Basse = 'basse';
    case Normale = 'normale';
    case Haute = 'haute';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Basse => 'gray',
            self::Normale => 'info',
            self::Haute => 'danger',
        };
    }
}
