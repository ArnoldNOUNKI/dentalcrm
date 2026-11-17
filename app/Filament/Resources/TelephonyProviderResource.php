<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelephonyProviderResource\Pages;
use App\Jobs\SyncTelephonyProvider;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\Telephony\CallSynchronizer;
use App\Services\Telephony\Drivers\TelephonyDriver;
use App\Services\Telephony\TelephonyManager;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Téléphonie → Intégrations : services connectés et leurs paramètres de connexion.
 */
class TelephonyProviderResource extends Resource
{
    protected static ?string $model = TelephonyProvider::class;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Téléphonie';

    protected static ?string $navigationLabel = 'Intégrations';

    protected static ?string $modelLabel = 'service de téléphonie';

    protected static ?string $pluralModelLabel = 'services de téléphonie';

    protected static ?int $navigationSort = 3;

    protected static ?string $breadcrumb = 'Intégrations';

    public static function canViewAny(): bool
    {
        return Auth::user()?->isAdmin() ?? false;
    }

    private static function driverFor(?string $key): ?TelephonyDriver
    {
        $manager = app(TelephonyManager::class);

        return $manager->has($key) ? $manager->driver($key) : null;
    }

    /**
     * Valeurs par défaut déclarées sur des champs (y compris dans des Fieldset).
     *
     * @param  array<Component>  $components
     */
    private static function defaults(array $components): array
    {
        $out = [];
        foreach ($components as $c) {
            if ($c instanceof Forms\Components\Field) {
                $default = $c->getDefaultState();
                if ($default !== null) {
                    $out[$c->getName()] = $default;
                }
            } else {
                $out += self::defaults($c->getChildComponents());
            }
        }

        return $out;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Service')->columns(3)->schema([
                Forms\Components\Select::make('driver')->label('Service de téléphonie')->required()
                    ->options(fn () => app(TelephonyManager::class)->options())
                    ->live()
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state, Get $get) {
                        $driver = self::driverFor($state);
                        if (blank($get('name')) && $driver) {
                            $set('name', $driver->label());
                        }
                        // Valeurs par défaut des champs propres au service choisi
                        $set('credentials', $driver ? self::defaults($driver->credentialFields()) : []);
                        $set('settings', $driver ? self::defaults($driver->settingFields()) : []);
                    })
                    ->disabledOn('edit')
                    ->helperText(fn (Get $get) => self::driverFor($get('driver'))?->description()),
                Forms\Components\TextInput::make('name')->label('Nom affiché')->required()->maxLength(100),
                Forms\Components\Grid::make(2)->columnSpan(1)->schema([
                    Forms\Components\Toggle::make('is_active')->label('Actif')->default(true)->inline(false),
                    Forms\Components\Toggle::make('is_default')->label('Click-to-call par défaut')->inline(false)
                        ->helperText('Service utilisé par le bouton « Appeler ».'),
                ]),
            ]),

            Forms\Components\Section::make('Paramètres de connexion')
                ->description('Chiffrés en base de données.')
                ->icon('heroicon-o-key')
                ->visible(fn (Get $get) => filled($get('driver')))
                ->schema(fn (Get $get) => [
                    Forms\Components\Group::make(self::driverFor($get('driver'))?->credentialFields() ?? [])
                        ->statePath('credentials')->columns(2),
                ]),

            Forms\Components\Section::make('Options')
                ->visible(fn (Get $get) => filled(self::driverFor($get('driver'))?->settingFields()))
                ->collapsible()
                ->schema(fn (Get $get) => [
                    Forms\Components\Group::make(self::driverFor($get('driver'))?->settingFields() ?? [])
                        ->statePath('settings')->columns(3),
                ]),

            Forms\Components\Section::make('Récupération automatique des appels')
                ->icon('heroicon-o-arrow-path')
                ->columns(3)
                ->visible(fn (Get $get) => self::driverFor($get('driver'))?->supportsSync() ?? false)
                ->schema([
                    Forms\Components\Toggle::make('sync_enabled')->label('Récupérer automatiquement')->default(true)->inline(false)
                        ->helperText('Appels émis, reçus, manqués, messageries, injoignables, durées et enregistrements.'),
                    Forms\Components\Select::make('sync_interval_minutes')->label('Fréquence')->default(5)
                        ->options([1 => 'Chaque minute', 5 => 'Toutes les 5 min', 15 => 'Toutes les 15 min', 30 => 'Toutes les 30 min', 60 => 'Toutes les heures']),
                    Forms\Components\Placeholder::make('sync_state')->label('Dernière récupération')
                        ->content(fn (?TelephonyProvider $record) => ! $record?->last_synced_at ? 'Jamais' : new HtmlString(
                            e($record->last_synced_at->diffForHumans()).' — '.($record->last_sync_status === 'ok'
                                ? e($record->last_sync_count).' appel(s)'
                                : '<span style="color:rgb(var(--danger-600))">erreur : '.e(Str::limit($record->last_error, 200)).'</span>')
                        )),
                ]),

            Forms\Components\Section::make('Webhook temps réel')
                ->icon('heroicon-o-bolt')
                ->visible(fn (Get $get) => self::driverFor($get('driver'))?->supportsWebhook() ?? false)
                ->schema([
                    Forms\Components\Placeholder::make('webhook_url')->label('URL à déclarer chez le fournisseur')
                        ->content(fn (?TelephonyProvider $record) => $record
                            ? new HtmlString('<code style="user-select:all;word-break:break-all">'.e($record->webhookUrl()).'</code>')
                            : 'Disponible après l\'enregistrement.'),
                    Forms\Components\Placeholder::make('webhook_help')->hiddenLabel()
                        ->content(fn (Get $get, ?TelephonyProvider $record) => $record ? new HtmlString((string) self::driverFor($get('driver'))?->webhookInstructions($record)) : null),
                    Forms\Components\Placeholder::make('webhook_last')->label('Dernier webhook reçu')
                        ->content(fn (?TelephonyProvider $record) => $record?->last_webhook_at?->diffForHumans() ?? 'Aucun'),
                ]),

            Forms\Components\Section::make('Agents')
                ->description('Associe chaque utilisateur du CRM à son compte, sa ligne ou son poste chez ce fournisseur : les appels lui sont attribués automatiquement et le click-to-call fait sonner son poste.')
                ->icon('heroicon-o-users')
                ->schema([
                    Forms\Components\Repeater::make('agents')->relationship()->hiddenLabel()->columns(4)->defaultItems(0)
                        ->addActionLabel('Ajouter un agent')
                        ->schema([
                            Forms\Components\Select::make('user_id')->label('Utilisateur CRM')->required()
                                ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                ->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            Forms\Components\TextInput::make('external_id')->label('ID utilisateur chez le fournisseur')
                                ->helperText('Ringover : user_id · Aircall : ID utilisateur'),
                            Forms\Components\TextInput::make('phone_number')->label('Ligne / téléphone de l\'agent')->tel()
                                ->helperText('Ringover : numéro de l\'agent · Twilio : téléphone appelé'),
                            Forms\Components\TextInput::make('extension')->label('Poste interne')
                                ->helperText('Asterisk / PBX : ex. 201'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Service')->weight('semibold')
                    ->description(fn (TelephonyProvider $r) => self::driverFor($r->driver)?->label()),
                Tables\Columns\ToggleColumn::make('is_active')->label('Actif'),
                Tables\Columns\IconColumn::make('is_default')->label('Click-to-call')->boolean(),
                Tables\Columns\TextColumn::make('last_synced_at')->label('Dernière récupération')->since()->placeholder('—')
                    ->description(fn (TelephonyProvider $r) => $r->last_sync_status === 'error' ? Str::limit((string) $r->last_error, 60) : ($r->last_synced_at ? $r->last_sync_count.' appel(s)' : null))
                    ->color(fn (TelephonyProvider $r) => $r->last_sync_status === 'error' ? 'danger' : null),
                Tables\Columns\TextColumn::make('last_webhook_at')->label('Dernier webhook')->since()->placeholder('—'),
                Tables\Columns\TextColumn::make('calls_count')->label('Appels')->counts('calls')->alignCenter(),
                Tables\Columns\TextColumn::make('agents_count')->label('Agents')->counts('agents')->alignCenter(),
            ])
            ->actions([
                Tables\Actions\Action::make('tester')->label('Tester la connexion')->icon('heroicon-o-signal')->color('gray')->iconButton()
                    ->action(function (TelephonyProvider $record) {
                        try {
                            Notification::make()->title($record->name)->body($record->driver()->testConnection($record))->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Échec de connexion : '.$record->name)->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),
                Tables\Actions\Action::make('synchroniser')->label('Récupérer les appels')->icon('heroicon-o-arrow-path')->color('primary')->iconButton()
                    ->visible(fn (TelephonyProvider $r) => $r->driver()->supportsSync())
                    ->form([
                        Forms\Components\Select::make('days')->label('Période')->default(1)->required()->options([
                            1 => 'Dernières 24 h', 7 => '7 derniers jours', 30 => '30 derniers jours', 90 => '90 derniers jours',
                        ]),
                    ])
                    ->action(function (TelephonyProvider $record, array $data) {
                        $from = now()->subDays((int) $data['days']);
                        if ((int) $data['days'] <= 1) {
                            try {
                                $n = app(CallSynchronizer::class)->sync($record, $from);
                                Notification::make()->title("{$n} appel(s) récupéré(s)")->success()->send();
                            } catch (\Throwable $e) {
                                Notification::make()->title('Échec de la récupération')->body($e->getMessage())->danger()->persistent()->send();
                            }
                        } else {
                            SyncTelephonyProvider::dispatch($record->id, $from->toIso8601String());
                            Notification::make()->title('Récupération lancée en arrière-plan')->body('Les appels apparaissent au fur et à mesure dans le journal.')->success()->send();
                        }
                    }),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('token')->label('Régénérer l\'URL du webhook')->icon('heroicon-o-key')
                        ->requiresConfirmation()->modalDescription('L\'ancienne URL cessera de fonctionner : pensez à mettre à jour le fournisseur.')
                        ->action(fn (TelephonyProvider $record) => $record->update(['webhook_token' => Str::random(40)])),
                    Tables\Actions\DeleteAction::make()
                        ->modalDescription('Les appels déjà récupérés sont conservés.'),
                ]),
            ])
            ->emptyStateHeading('Aucun service de téléphonie connecté')
            ->emptyStateDescription('Ajoutez Ringover, Aircall, Twilio, Asterisk ou tout autre service via webhook.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelephonyProviders::route('/'),
            'create' => Pages\CreateTelephonyProvider::route('/create'),
            'edit' => Pages\EditTelephonyProvider::route('/{record}/edit'),
        ];
    }
}
