<?php

namespace App\Filament\Resources\LeadResource\RelationManagers;

use App\Filament\Resources\CallResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CallsRelationManager extends RelationManager
{
    protected static string $relationship = 'calls';

    protected static ?string $title = 'Appels';

    protected static ?string $icon = 'heroicon-o-phone';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->calls()->count();
    }

    public function table(Table $table): Table
    {
        return CallResource::table($table)->poll('20s');
    }
}
