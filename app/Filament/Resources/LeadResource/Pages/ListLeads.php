<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Enums\LeadStatus;
use App\Filament\Imports\LeadImporter;
use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ImportAction::make()
                ->label('Importer un CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->importer(LeadImporter::class)
                ->maxRows(50000)
                ->chunkSize(200)
                ->csvDelimiter(null),
            Actions\CreateAction::make()->label('Nouveau lead'),
        ];
    }

    public function getTabs(): array
    {
        $base = fn () => Lead::query()->visibleTo();

        return [
            'tous' => Tab::make('Tous'),
            'a_appeler' => Tab::make('À appeler')
                ->icon('heroicon-o-phone-arrow-up-right')
                ->badge(fn () => $base()->callQueue()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->callQueue()->reorder()->orderBy('next_action_at')->orderByDesc('score')),
            'chauds' => Tab::make('Chauds')
                ->icon('heroicon-o-fire')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()->where('score', '>=', 70)),
            'interesses' => Tab::make('Intéressés')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [LeadStatus::Interesse->value, LeadStatus::KitEnvoye->value])),
            'clients' => Tab::make('Clients')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', LeadStatus::Client->value)),
            'perdus' => Tab::make('Perdus')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [LeadStatus::NonInteresse->value, LeadStatus::Invalide->value])),
        ];
    }
}
