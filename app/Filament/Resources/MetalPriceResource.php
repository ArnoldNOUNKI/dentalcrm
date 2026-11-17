<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MetalPriceResource\Pages;
use App\Models\MetalPrice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MetalPriceResource extends Resource
{
    protected static ?string $model = MetalPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-euro';

    protected static ?string $navigationGroup = 'Collectes';

    protected static ?string $modelLabel = 'cours métal';

    protected static ?string $pluralModelLabel = 'cours des métaux';

    protected static ?string $navigationLabel = 'Cours des métaux';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Auth::user()?->canSeeAllLeads() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')->label('Métal')->disabled(),
            Forms\Components\TextInput::make('price_per_gram')->label('Prix €/g de métal fin')->numeric()->required()->prefix('€'),
            Forms\Components\DateTimePicker::make('quoted_at')->label('Cotation du')->default(now())->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('label')->label('Métal')->weight('semibold'),
            Tables\Columns\TextColumn::make('price_per_gram')->label('€/g fin')->money('EUR', locale: 'fr'),
            Tables\Columns\TextColumn::make('kilo')->label('€/kg')->state(fn (MetalPrice $m) => $m->price_per_gram * 1000)->money('EUR', locale: 'fr'),
            Tables\Columns\TextColumn::make('quoted_at')->label('Cotation')->dateTime('d/m/Y H:i')->since(),
        ])->actions([
            Tables\Actions\EditAction::make()->mutateFormDataUsing(fn (array $data) => $data + ['quoted_at' => now()]),
        ])->paginated(false);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMetalPrices::route('/')];
    }
}
