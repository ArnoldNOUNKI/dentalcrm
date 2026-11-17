<?php

namespace App\Filament\Resources;

use App\Enums\ActivityType;
use App\Enums\CollectionStatus;
use App\Enums\LeadStatus;
use App\Filament\Resources\CollectionResource\Pages;
use App\Models\Collection;
use App\Models\Lead;
use App\Models\MetalPrice;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class CollectionResource extends Resource
{
    protected static ?string $model = Collection::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Collectes';

    protected static ?string $modelLabel = 'collecte';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo()->with(['lead', 'user']);
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Collection::query()->visibleTo()->whereIn('status', array_map(fn ($s) => $s->value, CollectionStatus::inProgress()))->count();

        return $n ? (string) $n : null;
    }

    public static function form(Form $form): Form
    {
        $pct = fn (string $name, string $label) => Forms\Components\TextInput::make($name)->label($label)->numeric()->minValue(0)->maxValue(100)->suffix('%')->live(onBlur: true);

        return $form->schema([
            Forms\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                Forms\Components\Section::make('Client & logistique')->columns(3)->schema([
                    Forms\Components\Select::make('lead_id')->label('Cabinet / labo')->required()->columnSpan(2)
                        ->relationship('lead', 'company_name', fn (Builder $query) => $query->visibleTo())
                        ->searchable()
                        ->default(fn () => request()->integer('lead') ?: null),
                    Forms\Components\Select::make('pickup_method')->label('Mode')->required()->default('kit_postal')
                        ->options(['kit_postal' => 'Kit postal', 'coursier' => 'Coursier', 'passage' => 'Passage commercial', 'depot' => 'Dépôt client']),
                    Forms\Components\DatePicker::make('kit_sent_at')->label('Kit envoyé le')->native(false),
                    Forms\Components\DatePicker::make('pickup_date')->label('Enlèvement prévu')->native(false),
                    Forms\Components\DatePicker::make('received_at')->label('Reçue le')->native(false),
                    Forms\Components\TextInput::make('carrier')->label('Transporteur')->datalist(['Colissimo', 'Chronopost', 'DHL', 'Coursier interne']),
                    Forms\Components\TextInput::make('tracking_number')->label('N° de suivi')->columnSpan(2),
                ]),
                Forms\Components\Section::make('Contenu')->schema([
                    Forms\Components\Repeater::make('items')->hiddenLabel()->columns(3)->defaultItems(0)
                        ->addActionLabel('Ajouter un type de pièce')
                        ->schema([
                            Forms\Components\Select::make('type')->label('Type')->required()->options([
                                'couronnes' => 'Couronnes', 'bridges' => 'Bridges', 'inlays' => 'Inlays / onlays',
                                'alliages' => 'Chutes d\'alliage', 'limailles' => 'Limailles / poussières', 'crochets' => 'Crochets / stellites',
                                'amalgame' => 'Amalgame', 'autre' => 'Autre',
                            ]),
                            Forms\Components\TextInput::make('quantity')->label('Quantité')->numeric(),
                            Forms\Components\TextInput::make('note')->label('Précision'),
                        ]),
                ]),
                Forms\Components\Section::make('Pesée & titrage')
                    ->description(fn () => 'Cours utilisés (€/g fin) : '.collect(MetalPrice::current())->map(fn ($p, $m) => (MetalPrice::METALS[$m] ?? $m).' '.number_format($p, 2, ',', ' '))->implode(' · '))
                    ->columns(4)->schema([
                        Forms\Components\TextInput::make('gross_weight_g')->label('Poids brut')->numeric()->suffix('g')->live(onBlur: true),
                        Forms\Components\TextInput::make('net_weight_g')->label('Poids net (après fonte)')->numeric()->suffix('g')->live(onBlur: true),
                        Forms\Components\TextInput::make('payout_rate')->label('Taux reversé')->numeric()->suffix('%')->default(85)->live(onBlur: true),
                        Forms\Components\Placeholder::make('valeur')->label('Valeur estimée')
                            ->content(fn (Get $get) => ($v = Collection::computeValue([
                                'net_weight_g' => $get('net_weight_g'), 'gross_weight_g' => $get('gross_weight_g'),
                                'au_pct' => $get('au_pct'), 'pt_pct' => $get('pt_pct'), 'pd_pct' => $get('pd_pct'), 'ag_pct' => $get('ag_pct'),
                                'payout_rate' => $get('payout_rate'),
                            ], MetalPrice::current())) ? Number::currency($v, 'EUR', 'fr') : '—'),
                        $pct('au_pct', 'Or (Au)'),
                        $pct('pt_pct', 'Platine (Pt)'),
                        $pct('pd_pct', 'Palladium (Pd)'),
                        $pct('ag_pct', 'Argent (Ag)'),
                    ]),
            ]),
            Forms\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                Forms\Components\Section::make('Statut')->schema([
                    Forms\Components\Select::make('status')->label('Statut')->options(CollectionStatus::class)->required()->default(CollectionStatus::Planifiee->value),
                    Forms\Components\Select::make('user_id')->label('Commercial')->options(fn () => User::salesAgents()->pluck('name', 'id'))
                        ->default(fn () => Auth::id()),
                    Forms\Components\Select::make('handled_by')->label('Logistique')->options(fn () => User::where('is_active', true)->pluck('name', 'id')),
                ]),
                Forms\Components\Section::make('Financier')->schema([
                    Forms\Components\TextInput::make('offer_amount')->label('Offre de rachat')->numeric()->prefix('€'),
                    Forms\Components\DatePicker::make('offer_sent_at')->label('Offre envoyée le')->native(false),
                    Forms\Components\TextInput::make('paid_amount')->label('Montant payé')->numeric()->prefix('€'),
                    Forms\Components\DatePicker::make('paid_at')->label('Payée le')->native(false),
                    Forms\Components\Select::make('payment_method')->label('Paiement')->options(['virement' => 'Virement', 'cheque' => 'Chèque', 'avoir' => 'Avoir']),
                ]),
                Forms\Components\Textarea::make('notes')->rows(4),
            ]),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reference')->label('Réf.')->searchable()->weight('semibold'),
                Tables\Columns\TextColumn::make('lead.company_name')->label('Client')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()->sortable(),
                Tables\Columns\TextColumn::make('received_at')->label('Reçue')->date('d/m/Y')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('net_weight_g')->label('Poids net')->suffix(' g')->numeric(2, ',', ' ')->placeholder('—')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->suffix(' g')),
                Tables\Columns\TextColumn::make('estimated_value')->label('Valeur estimée')->money('EUR', locale: 'fr')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('offer_amount')->label('Offre')->money('EUR', locale: 'fr')->placeholder('—'),
                Tables\Columns\TextColumn::make('paid_amount')->label('Payé')->money('EUR', locale: 'fr')->placeholder('—')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Total')->money('EUR', locale: 'fr')),
                Tables\Columns\TextColumn::make('user.name')->label('Commercial')->toggleable(),
                Tables\Columns\TextColumn::make('tracking_number')->label('Suivi')->toggleable(isToggledHiddenByDefault: true)->copyable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options(CollectionStatus::class)->multiple(),
                Tables\Filters\Filter::make('en_cours')->label('En cours')->toggle()
                    ->query(fn (Builder $query) => $query->whereIn('status', array_map(fn ($s) => $s->value, CollectionStatus::inProgress()))),
                Tables\Filters\SelectFilter::make('user_id')->label('Commercial')->relationship('user', 'name'),
            ])
            ->actions([
                Tables\Actions\Action::make('avancer')
                    ->label('Étape suivante')
                    ->icon('heroicon-o-forward')
                    ->color('primary')
                    ->iconButton()
                    ->visible(fn (Collection $r) => static::nextStatus($r->status) !== null)
                    ->tooltip(fn (Collection $r) => 'Passer à : '.static::nextStatus($r->status)?->getLabel())
                    ->requiresConfirmation()
                    ->action(function (Collection $r) {
                        static::moveTo($r, static::nextStatus($r->status));
                        Notification::make()->title('Collecte : '.$r->status->getLabel())->success()->send();
                    }),
                Tables\Actions\EditAction::make()->iconButton(),
            ]);
    }

    public static function nextStatus(CollectionStatus $s): ?CollectionStatus
    {
        return match ($s) {
            CollectionStatus::Planifiee => CollectionStatus::KitEnvoye,
            CollectionStatus::KitEnvoye => CollectionStatus::EnTransit,
            CollectionStatus::EnTransit => CollectionStatus::Recue,
            CollectionStatus::Recue => CollectionStatus::EnAnalyse,
            CollectionStatus::EnAnalyse => CollectionStatus::OffreEnvoyee,
            CollectionStatus::OffreEnvoyee => CollectionStatus::Acceptee,
            CollectionStatus::Acceptee => CollectionStatus::Payee,
            default => null,
        };
    }

    /** Change le statut d'une collecte et répercute sur le lead (timeline + pipeline). */
    public static function moveTo(Collection $c, CollectionStatus $status): void
    {
        $dates = match ($status) {
            CollectionStatus::KitEnvoye => ['kit_sent_at' => $c->kit_sent_at ?? today()],
            CollectionStatus::Recue => ['received_at' => $c->received_at ?? today()],
            CollectionStatus::OffreEnvoyee => ['offer_sent_at' => $c->offer_sent_at ?? today(), 'offer_amount' => $c->offer_amount ?? $c->estimated_value],
            CollectionStatus::Payee => ['paid_at' => $c->paid_at ?? today(), 'paid_amount' => $c->paid_amount ?? $c->offer_amount],
            default => [],
        };

        $c->update(['status' => $status] + $dates);
        static::syncLead($c);
    }

    public static function syncLead(Collection $c): void
    {
        $lead = $c->lead;
        if (! $lead) {
            return;
        }

        $lead->logActivity(ActivityType::Collection, "Collecte {$c->reference} : ".$c->status->getLabel(), null, ['collection_id' => $c->id]);

        $target = match ($c->status) {
            CollectionStatus::KitEnvoye, CollectionStatus::EnTransit => LeadStatus::KitEnvoye,
            CollectionStatus::Recue, CollectionStatus::EnAnalyse, CollectionStatus::OffreEnvoyee, CollectionStatus::Acceptee, CollectionStatus::Payee => LeadStatus::Client,
            default => null,
        };

        if ($target && $lead->status !== LeadStatus::Client) {
            $lead->changeStatus($target, 'Mise à jour automatique depuis la collecte '.$c->reference);
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollections::route('/'),
            'create' => Pages\CreateCollection::route('/create'),
            'edit' => Pages\EditCollection::route('/{record}/edit'),
        ];
    }
}
