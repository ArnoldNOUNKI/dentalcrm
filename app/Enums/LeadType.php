<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LeadType: string implements HasLabel
{
    case Cabinet = 'cabinet';
    case Laboratoire = 'laboratoire';
    case Centre = 'centre';
    case Orthodontiste = 'orthodontiste';
    case Stomatologue = 'stomatologue';
    case Clinique = 'clinique';
    case Autre = 'autre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cabinet => 'Cabinet dentaire',
            self::Laboratoire => 'Laboratoire de prothèse',
            self::Centre => 'Centre dentaire',
            self::Orthodontiste => 'Orthodontiste',
            self::Stomatologue => 'Stomatologue',
            self::Clinique => 'Clinique / hôpital',
            self::Autre => 'Autre',
        };
    }

    /** Devine le type à partir d'un libellé libre (import CSV). */
    public static function guess(?string $value): self
    {
        $v = mb_strtolower(trim((string) $value));

        foreach (self::cases() as $case) {
            if ($v === $case->value || $v === mb_strtolower($case->getLabel())) {
                return $case;
            }
        }

        return match (true) {
            str_contains($v, 'labo') || str_contains($v, 'prothes') || str_contains($v, 'prothès') => self::Laboratoire,
            str_contains($v, 'centre') => self::Centre,
            str_contains($v, 'ortho') => self::Orthodontiste,
            str_contains($v, 'stomato') => self::Stomatologue,
            str_contains($v, 'clinique') || str_contains($v, 'hôpital') || str_contains($v, 'hopital') || str_contains($v, 'chu') => self::Clinique,
            str_contains($v, 'cabinet') || str_contains($v, 'dentist') || str_contains($v, 'chirurgien') => self::Cabinet,
            default => self::Autre,
        };
    }
}
