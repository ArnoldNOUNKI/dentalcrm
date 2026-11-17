<?php

namespace App\Filament\Resources\TelephonyProviderResource\Pages;

use App\Filament\Resources\TelephonyProviderResource;
use App\Models\TelephonyProvider;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTelephonyProviders extends ListRecords
{
    protected static string $resource = TelephonyProviderResource::class;

    protected static ?string $title = 'Intégrations téléphonie';

    public function mount(): void
    {
        parent::mount();
        // Reprend automatiquement la configuration Ringover des anciennes variables d'environnement
        TelephonyProvider::ringoverFromEnv();
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Connecter un service')];
    }
}
