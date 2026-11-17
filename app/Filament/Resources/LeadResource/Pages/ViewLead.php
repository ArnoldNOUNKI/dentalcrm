<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\CollectionResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Support\LeadActions;
use App\Models\Lead;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    public function getTitle(): string
    {
        return $this->record->company_name;
    }

    public function getSubheading(): ?string
    {
        return collect([$this->record->reference, $this->record->type?->getLabel(), trim($this->record->postal_code.' '.$this->record->city)])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            LeadActions::call(Actions\Action::class),
            LeadActions::logCall(Actions\Action::class),
            LeadActions::followUp(Actions\Action::class),
            Actions\ActionGroup::make([
                LeadActions::aiScore(Actions\Action::class),
                LeadActions::aiEmail(Actions\Action::class),
                LeadActions::aiAsk(Actions\Action::class),
            ])->label('IA')->icon('heroicon-o-sparkles')->color('primary')->button(),
            Actions\ActionGroup::make([
                LeadActions::changeStatus(Actions\Action::class),
                LeadActions::note(Actions\Action::class),
                LeadActions::assign(Actions\Action::class),
                Actions\Action::make('collecte')
                    ->label('Nouvelle collecte')
                    ->icon('heroicon-o-cube')
                    ->url(fn (Lead $record) => CollectionResource::getUrl('create', ['lead' => $record->id])),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])->icon('heroicon-m-ellipsis-vertical')->color('gray')->button()->label('Plus'),
        ];
    }
}
