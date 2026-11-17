<?php

namespace App\Filament\Resources\TelephonyProviderResource\Pages;

use App\Filament\Resources\TelephonyProviderResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditTelephonyProvider extends EditRecord
{
    protected static string $resource = TelephonyProviderResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    /** credentials est masqué à la sérialisation : on le fournit explicitement au formulaire. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['credentials'] = $this->record->credentials ?? [];
        $data['settings'] = $this->record->settings ?? [];

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('tester')->label('Tester la connexion')->icon('heroicon-o-signal')->color('gray')
                ->action(function () {
                    try {
                        Notification::make()->title('Connexion OK')->body($this->record->driver()->testConnection($this->record))->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Échec de connexion')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
