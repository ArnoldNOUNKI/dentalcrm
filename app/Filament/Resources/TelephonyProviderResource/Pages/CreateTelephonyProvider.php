<?php

namespace App\Filament\Resources\TelephonyProviderResource\Pages;

use App\Filament\Resources\TelephonyProviderResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTelephonyProvider extends CreateRecord
{
    protected static string $resource = TelephonyProviderResource::class;

    protected static ?string $title = 'Connecter un service de téléphonie';

    protected function afterCreate(): void
    {
        try {
            Notification::make()->title('Connexion vérifiée')->body($this->record->driver()->testConnection($this->record))->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Service enregistré, mais la connexion échoue')->body($e->getMessage())->warning()->persistent()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        // Retour sur la fiche pour afficher l'URL du webhook
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
