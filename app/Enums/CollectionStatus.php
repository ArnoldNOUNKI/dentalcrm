<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie d'une collecte de métaux dentaires (couronnes, bridges, alliages, limailles…).
 */
enum CollectionStatus: string implements HasColor, HasLabel
{
    case Planifiee = 'planifiee';
    case KitEnvoye = 'kit_envoye';
    case EnTransit = 'en_transit';
    case Recue = 'recue';
    case EnAnalyse = 'en_analyse';
    case OffreEnvoyee = 'offre_envoyee';
    case Acceptee = 'acceptee';
    case Payee = 'payee';
    case Refusee = 'refusee';
    case Retournee = 'retournee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Planifiee => 'Planifiée',
            self::KitEnvoye => 'Kit envoyé',
            self::EnTransit => 'En transit',
            self::Recue => 'Reçue',
            self::EnAnalyse => 'En analyse / titrage',
            self::OffreEnvoyee => 'Offre envoyée',
            self::Acceptee => 'Offre acceptée',
            self::Payee => 'Payée',
            self::Refusee => 'Offre refusée',
            self::Retournee => 'Retournée au client',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planifiee, self::KitEnvoye => 'gray',
            self::EnTransit, self::Recue => 'info',
            self::EnAnalyse, self::OffreEnvoyee => 'warning',
            self::Acceptee => 'primary',
            self::Payee => 'success',
            self::Refusee, self::Retournee => 'danger',
        };
    }

    public static function inProgress(): array
    {
        return [self::Planifiee, self::KitEnvoye, self::EnTransit, self::Recue, self::EnAnalyse, self::OffreEnvoyee, self::Acceptee];
    }
}
