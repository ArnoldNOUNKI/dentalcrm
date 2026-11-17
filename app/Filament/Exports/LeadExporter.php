<?php

namespace App\Filament\Exports;

use App\Models\Lead;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class LeadExporter extends Exporter
{
    protected static ?string $model = Lead::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('reference')->label('Référence'),
            ExportColumn::make('company_name')->label('Établissement'),
            ExportColumn::make('type')->label('Type')->formatStateUsing(fn ($state) => $state?->getLabel()),
            ExportColumn::make('contact_civility')->label('Civilité'),
            ExportColumn::make('contact_first_name')->label('Prénom'),
            ExportColumn::make('contact_last_name')->label('Nom'),
            ExportColumn::make('contact_role')->label('Fonction'),
            ExportColumn::make('phone_normalized')->label('Téléphone'),
            ExportColumn::make('mobile_normalized')->label('Mobile'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('address')->label('Adresse'),
            ExportColumn::make('postal_code')->label('Code postal'),
            ExportColumn::make('city')->label('Ville'),
            ExportColumn::make('siret')->label('SIRET'),
            ExportColumn::make('status')->label('Statut')->formatStateUsing(fn ($state) => $state?->getLabel()),
            ExportColumn::make('score')->label('Score IA'),
            ExportColumn::make('assignee.name')->label('Commercial'),
            ExportColumn::make('call_attempts')->label('Tentatives'),
            ExportColumn::make('last_contacted_at')->label('Dernier contact'),
            ExportColumn::make('next_action_at')->label('Prochaine action'),
            ExportColumn::make('current_buyer')->label('Récupérateur actuel'),
            ExportColumn::make('estimated_grams_year')->label('Volume estimé (g/an)'),
            ExportColumn::make('source')->label('Source'),
            ExportColumn::make('ai_summary')->label('Synthèse IA'),
            ExportColumn::make('created_at')->label('Créé le'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Export terminé : '.Number::format($export->successful_rows).' lead(s) exporté(s).';
    }
}
