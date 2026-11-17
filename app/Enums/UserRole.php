<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Commercial = 'commercial';
    case Logistique = 'logistique';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Manager => 'Manager',
            self::Commercial => 'Commercial / téléprospecteur',
            self::Logistique => 'Logistique / laboratoire',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Manager => 'warning',
            self::Commercial => 'success',
            self::Logistique => 'info',
        };
    }
}
