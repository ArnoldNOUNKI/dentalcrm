<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Issue d'un appel, saisie par le commercial ou déduite par l'IA.
 */
enum CallOutcome: string implements HasColor, HasLabel
{
    case Repondu = 'repondu';
    case PasDeReponse = 'pas_de_reponse';
    case Messagerie = 'messagerie';
    case Barrage = 'barrage';
    case Rappel = 'rappel';
    case Interesse = 'interesse';
    case KitDemande = 'kit_demande';
    case PasInteresse = 'pas_interesse';
    case MauvaisNumero = 'mauvais_numero';

    public function getLabel(): string
    {
        return match ($this) {
            self::Repondu => 'Décroché',
            self::PasDeReponse => 'Pas de réponse',
            self::Messagerie => 'Messagerie',
            self::Barrage => 'Barrage secrétariat',
            self::Rappel => 'Rappel demandé',
            self::Interesse => 'Intéressé',
            self::KitDemande => 'Kit de collecte demandé',
            self::PasInteresse => 'Pas intéressé',
            self::MauvaisNumero => 'Mauvais numéro',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Interesse, self::KitDemande => 'success',
            self::Rappel, self::Barrage, self::Repondu => 'warning',
            self::PasInteresse, self::MauvaisNumero => 'danger',
            default => 'gray',
        };
    }

    /** Statut technique correspondant (saisie manuelle d'un appel). */
    public function toCallStatus(): CallStatus
    {
        return match ($this) {
            self::PasDeReponse, self::MauvaisNumero => CallStatus::NoAnswer,
            self::Messagerie => CallStatus::Voicemail,
            default => CallStatus::Answered,
        };
    }

    /** Statut lead à appliquer après cet appel (null = inchangé). */
    public function nextLeadStatus(): ?LeadStatus
    {
        return match ($this) {
            self::PasDeReponse, self::Messagerie => LeadStatus::Injoignable,
            self::Barrage, self::Rappel => LeadStatus::ARappeler,
            self::Interesse => LeadStatus::Interesse,
            self::KitDemande => LeadStatus::Interesse,
            self::PasInteresse => LeadStatus::NonInteresse,
            self::MauvaisNumero => LeadStatus::Invalide,
            self::Repondu => null,
        };
    }
}
