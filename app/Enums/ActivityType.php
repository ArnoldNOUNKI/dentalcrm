<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum ActivityType: string implements HasColor, HasIcon, HasLabel
{
    case Note = 'note';
    case Call = 'call';
    case Email = 'email';
    case StatusChange = 'status_change';
    case Assignment = 'assignment';
    case Task = 'task';
    case Collection = 'collection';
    case Ai = 'ai';
    case Import = 'import';

    public function getLabel(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Call => 'Appel',
            self::Email => 'Email',
            self::StatusChange => 'Changement de statut',
            self::Assignment => 'Attribution',
            self::Task => 'Rappel / tâche',
            self::Collection => 'Collecte',
            self::Ai => 'Analyse IA',
            self::Import => 'Import',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Call => 'success',
            self::Email => 'info',
            self::StatusChange, self::Assignment => 'warning',
            self::Collection => 'primary',
            self::Ai => 'danger',
            default => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Note => 'heroicon-o-pencil-square',
            self::Call => 'heroicon-o-phone',
            self::Email => 'heroicon-o-envelope',
            self::StatusChange => 'heroicon-o-arrows-right-left',
            self::Assignment => 'heroicon-o-user-plus',
            self::Task => 'heroicon-o-bell-alert',
            self::Collection => 'heroicon-o-cube',
            self::Ai => 'heroicon-o-sparkles',
            self::Import => 'heroicon-o-arrow-up-tray',
        };
    }
}
