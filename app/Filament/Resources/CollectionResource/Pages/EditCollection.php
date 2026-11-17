<?php

namespace App\Filament\Resources\CollectionResource\Pages;

use App\Filament\Resources\CollectionResource;
use App\Filament\Resources\LeadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCollection extends EditRecord
{
    protected static string $resource = CollectionResource::class;

    protected ?string $previousStatus = null;

    protected function beforeSave(): void
    {
        $s = $this->record->getOriginal('status');
        $this->previousStatus = $s instanceof \BackedEnum ? $s->value : $s;
    }

    protected function afterSave(): void
    {
        if ($this->previousStatus !== $this->record->status->value) {
            CollectionResource::syncLead($this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('lead')->label('Voir le client')->icon('heroicon-o-building-office-2')->color('gray')
                ->url(fn () => LeadResource::getUrl('view', ['record' => $this->record->lead_id])),
            Actions\DeleteAction::make(),
        ];
    }
}
