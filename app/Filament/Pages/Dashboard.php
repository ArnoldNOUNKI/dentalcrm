<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Tableau de bord';

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\Select::make('period')->label('Période')->default('30')->selectablePlaceholder(false)
                    ->options(['1' => 'Aujourd\'hui', '7' => '7 derniers jours', '30' => '30 derniers jours', '90' => '90 derniers jours', '365' => '12 mois']),
                Forms\Components\Select::make('user_id')->label('Commercial')->placeholder('Toute l\'équipe')
                    ->options(fn () => User::salesAgents()->pluck('name', 'id'))
                    ->visible(fn () => Auth::user()->canSeeAllLeads()),
            ]),
        ]);
    }

    public function getColumns(): int|string|array
    {
        return ['md' => 2, 'xl' => 3];
    }
}
