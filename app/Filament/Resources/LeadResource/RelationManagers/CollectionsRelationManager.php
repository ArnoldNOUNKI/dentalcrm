<?php

namespace App\Filament\Resources\LeadResource\RelationManagers;

use App\Filament\Resources\CollectionResource;
use App\Models\Collection;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CollectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'collections';

    protected static ?string $title = 'Collectes';

    protected static ?string $icon = 'heroicon-o-cube';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $n = $ownerRecord->collections()->count();

        return $n ? (string) $n : null;
    }

    public function table(Table $table): Table
    {
        return CollectionResource::table($table)
            ->headerActions([
                Tables\Actions\Action::make('nouvelle')->label('Nouvelle collecte')->icon('heroicon-o-plus')
                    ->url(fn () => CollectionResource::getUrl('create', ['lead' => $this->getOwnerRecord()->id])),
            ])
            ->recordUrl(fn (Collection $record) => CollectionResource::getUrl('edit', ['record' => $record]));
    }
}
