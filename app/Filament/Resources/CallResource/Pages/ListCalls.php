<?php

namespace App\Filament\Resources\CallResource\Pages;

use App\Filament\Resources\CallResource;
use App\Services\Ringover\RingoverClient;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

class ListCalls extends ListRecords
{
    protected static string $resource = CallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Synchroniser Ringover')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => app(RingoverClient::class)->isConfigured() && Auth::user()->canManageTeam())
                ->action(function () {
                    try {
                        Artisan::call('ringover:sync', ['--minutes' => 1440]);
                        Notification::make()->title('Synchronisation terminée')->body(trim(Artisan::output()))->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Erreur Ringover')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
