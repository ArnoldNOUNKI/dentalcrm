<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Pipeline commercial d'un prospect (cabinet / labo) pour la collecte de métaux dentaires.
 */
enum LeadStatus: string implements HasColor, HasIcon, HasLabel
{
    case Nouveau = 'nouveau';
    case AAppeler = 'a_appeler';
    case Injoignable = 'injoignable';
    case ARappeler = 'a_rappeler';
    case Interesse = 'interesse';
    case KitEnvoye = 'kit_envoye';
    case Client = 'client';
    case NonInteresse = 'non_interesse';
    case Invalide = 'invalide';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nouveau => 'Nouveau',
            self::AAppeler => 'À appeler',
            self::Injoignable => 'Injoignable',
            self::ARappeler => 'À rappeler',
            self::Interesse => 'Intéressé',
            self::KitEnvoye => 'Kit envoyé',
            self::Client => 'Client actif',
            self::NonInteresse => 'Pas intéressé',
            self::Invalide => 'Invalide / hors cible',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Nouveau, self::Invalide => 'gray',
            self::AAppeler => 'info',
            self::Injoignable, self::ARappeler => 'warning',
            self::Interesse, self::Client => 'success',
            self::KitEnvoye => 'primary',
            self::NonInteresse => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Nouveau => 'heroicon-o-sparkles',
            self::AAppeler => 'heroicon-o-phone-arrow-up-right',
            self::Injoignable => 'heroicon-o-phone-x-mark',
            self::ARappeler => 'heroicon-o-clock',
            self::Interesse => 'heroicon-o-hand-thumb-up',
            self::KitEnvoye => 'heroicon-o-cube',
            self::Client => 'heroicon-o-check-badge',
            self::NonInteresse => 'heroicon-o-hand-thumb-down',
            self::Invalide => 'heroicon-o-no-symbol',
        };
    }

    /** Statuts encore « ouverts » : le lead doit être travaillé. */
    public static function open(): array
    {
        return [self::Nouveau, self::AAppeler, self::Injoignable, self::ARappeler, self::Interesse, self::KitEnvoye];
    }

    /** Statuts éligibles à la file d'appels. */
    public static function callable(): array
    {
        return [self::Nouveau, self::AAppeler, self::Injoignable, self::ARappeler, self::Interesse];
    }

    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
