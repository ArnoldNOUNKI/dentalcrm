<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum TaskType: string implements HasIcon, HasLabel
{
    case Rappel = 'rappel';
    case Email = 'email';
    case Relance = 'relance';
    case Collecte = 'collecte';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rappel => 'Rappel téléphonique',
            self::Email => 'Email à envoyer',
            self::Relance => 'Relance',
            self::Collecte => 'Action collecte',
            self::Autre => 'Autre',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Rappel => 'heroicon-o-phone',
            self::Email => 'heroicon-o-envelope',
            self::Relance => 'heroicon-o-arrow-path',
            self::Collecte => 'heroicon-o-cube',
            self::Autre => 'heroicon-o-clipboard-document',
        };
    }
}
