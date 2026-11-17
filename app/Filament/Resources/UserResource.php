<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $modelLabel = 'utilisateur';

    protected static ?string $navigationLabel = 'Équipe';

    public static function canViewAny(): bool
    {
        return Auth::user()?->canManageTeam() ?? false;
    }

    public static function canEdit($record): bool
    {
        // Un manager ne peut pas modifier un administrateur
        return Auth::user()?->isAdmin() || ($record->role !== UserRole::Admin && (Auth::user()?->canManageTeam() ?? false));
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->isAdmin() && $record->id !== Auth::id();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Compte')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Nom')->required(),
                Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                Forms\Components\Select::make('role')->label('Rôle')->options(UserRole::class)->required()->default(UserRole::Commercial->value)
                    // Seul un admin peut créer un admin
                    ->disableOptionWhen(fn (string $value) => $value === UserRole::Admin->value && ! Auth::user()->isAdmin()),
                Forms\Components\TextInput::make('password')->label('Mot de passe')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->minLength(10),
                Forms\Components\Toggle::make('is_active')->label('Actif')->default(true),
                Forms\Components\Toggle::make('receives_leads')->label('Reçoit les leads importés')->default(true),
            ]),
            Forms\Components\Section::make('Téléphonie')->columns(2)
                ->description('Les comptes, lignes et postes de chaque service (Ringover, Aircall, Twilio, Asterisk…) se configurent dans Téléphonie → Intégrations → Agents.')
                ->schema([
                    Forms\Components\TextInput::make('daily_call_target')->label('Objectif d\'appels / jour')->numeric()->default(80),
                    Forms\Components\TextInput::make('phone')->label('Téléphone de l\'agent')->tel()
                        ->helperText('Utilisé par défaut par Twilio pour faire sonner l\'agent.'),
                    Forms\Components\TextInput::make('ringover_number')->label('Numéro Ringover (ancien réglage)')->tel(),
                    Forms\Components\TextInput::make('ringover_user_id')->label('ID utilisateur Ringover (ancien réglage)'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->label('Nom')->searchable()->description(fn (User $u) => $u->email),
            Tables\Columns\TextColumn::make('role')->label('Rôle')->badge(),
            Tables\Columns\TextColumn::make('leads_count')->label('Leads')->counts('leads')->alignCenter(),
            Tables\Columns\TextColumn::make('open_leads')->label('Ouverts')->alignCenter()
                ->state(fn (User $u) => $u->leads()->open()->count()),
            Tables\Columns\TextColumn::make('calls_today')->label('Appels aujourd\'hui')->alignCenter()
                ->state(fn (User $u) => $u->calls()->where('started_at', '>=', today())->count()),
            Tables\Columns\IconColumn::make('receives_leads')->label('Distribution')->boolean(),
            Tables\Columns\IconColumn::make('is_active')->label('Actif')->boolean(),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
