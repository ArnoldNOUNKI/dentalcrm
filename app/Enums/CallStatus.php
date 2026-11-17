<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut technique d'un appel, normalisé quel que soit le fournisseur.
 */
enum CallStatus: string implements HasColor, HasIcon, HasLabel
{
    case InProgress = 'in_progress';
    case Answered = 'answered';
    case Missed = 'missed';        // entrant non décroché
    case Voicemail = 'voicemail';  // entrant parti sur la messagerie
    case NoAnswer = 'no_answer';   // sortant non décroché (injoignable)
    case Busy = 'busy';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::InProgress => 'En cours',
            self::Answered => 'Décroché',
            self::Missed => 'Manqué',
            self::Voicemail => 'Messagerie',
            self::NoAnswer => 'Injoignable',
            self::Busy => 'Occupé',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Answered => 'success',
            self::InProgress => 'info',
            self::Voicemail, self::Busy => 'warning',
            self::Missed, self::NoAnswer, self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Answered => 'heroicon-o-phone',
            self::InProgress => 'heroicon-o-phone-arrow-up-right',
            self::Voicemail => 'heroicon-o-inbox-arrow-down',
            default => 'heroicon-o-phone-x-mark',
        };
    }

    public function isAnswered(): bool
    {
        return $this === self::Answered;
    }

    /** Statut à partir d'un libellé quelconque (webhook générique, PBX…). */
    public static function guess(?string $value, string $direction, bool $answered = false): self
    {
        $v = strtolower(trim((string) $value));

        return match (true) {
            in_array($v, ['answered', 'completed', 'done_answered', 'answer', 'decroche', 'décroché', 'connected'], true) => self::Answered,
            in_array($v, ['voicemail', 'messagerie', 'vm'], true) => self::Voicemail,
            in_array($v, ['busy', 'occupe', 'occupé'], true) => self::Busy,
            in_array($v, ['failed', 'congestion', 'error', 'echec', 'échec'], true) => self::Failed,
            in_array($v, ['cancelled', 'canceled', 'abandoned', 'annule', 'annulé'], true) => $direction === 'in' ? self::Missed : self::Cancelled,
            in_array($v, ['ringing', 'in-progress', 'in_progress', 'initiated', 'queued', 'created', 'initial'], true) => self::InProgress,
            in_array($v, ['missed', 'no-answer', 'no_answer', 'noanswer', 'no answer', 'unanswered', 'manque', 'manqué'], true) => $direction === 'in' ? self::Missed : self::NoAnswer,
            $answered => self::Answered,
            default => $direction === 'in' ? self::Missed : self::NoAnswer,
        };
    }
}
