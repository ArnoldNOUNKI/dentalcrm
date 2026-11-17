<?php

namespace App\Filament\Resources;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Enums\LeadType;
use App\Filament\Exports\LeadExporter;
use App\Filament\Resources\LeadResource\Pages;
use App\Filament\Resources\LeadResource\RelationManagers;
use App\Filament\Support\LeadActions;
use App\Jobs\ScoreLeadWithAi;
use App\Models\Lead;
use App\Models\User;
use App\Services\Ai\LeadIntelligence;
use App\Services\LeadAssigner;
use App\Services\PhoneNormalizer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Prospection';

    protected static ?string $modelLabel = 'lead';

    protected static ?string $pluralModelLabel = 'leads';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'company_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleTo()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Lead::query()->visibleTo()->callQueue()->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Leads à appeler maintenant';
    }

    // ---------------------------------------------------------------- Recherche globale

    public static function getGloballySearchableAttributes(): array
    {
        return ['company_name', 'reference', 'city', 'postal_code', 'phone_normalized', 'mobile_normalized', 'email', 'contact_last_name', 'siret'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            'Ville' => $record->city,
            'Statut' => $record->status?->getLabel(),
            'Tél.' => PhoneNormalizer::format($record->phone_normalized),
        ]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->visibleTo();
    }

    // ---------------------------------------------------------------- Formulaire

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                Forms\Components\Section::make('Établissement')->columns(2)->schema([
                    Forms\Components\TextInput::make('company_name')->label('Raison sociale / nom du cabinet')->required()->maxLength(255)->columnSpanFull(),
                    Forms\Components\Select::make('type')->label('Type')->options(LeadType::class)->required()->default(LeadType::Cabinet->value),
                    Forms\Components\TextInput::make('practitioners_count')->label('Nb de praticiens')->numeric()->minValue(0),
                    Forms\Components\TextInput::make('siret')->label('SIRET')->maxLength(20),
                    Forms\Components\TextInput::make('website')->label('Site web')->url()->maxLength(255),
                ]),
                Forms\Components\Section::make('Contact')->columns(3)->schema([
                    Forms\Components\Select::make('contact_civility')->label('Civilité')->options(['Dr' => 'Dr', 'M.' => 'M.', 'Mme' => 'Mme']),
                    Forms\Components\TextInput::make('contact_first_name')->label('Prénom'),
                    Forms\Components\TextInput::make('contact_last_name')->label('Nom'),
                    Forms\Components\TextInput::make('contact_role')->label('Fonction')->datalist(['Chirurgien-dentiste', 'Gérant', 'Assistante', 'Secrétaire', 'Prothésiste', 'Directeur de centre']),
                    Forms\Components\TextInput::make('phone')->label('Téléphone')->tel()
                        ->rule(fn (?Model $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                            $n = PhoneNormalizer::normalize($value);
                            if ($n && Lead::where('phone_normalized', $n)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                                $fail('Ce numéro existe déjà sur un autre lead.');
                            }
                        }),
                    Forms\Components\TextInput::make('mobile')->label('Mobile')->tel(),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->columnSpan(2),
                ]),
                Forms\Components\Section::make('Adresse')->columns(3)->collapsible()->schema([
                    Forms\Components\TextInput::make('address')->label('Adresse')->columnSpanFull(),
                    Forms\Components\TextInput::make('postal_code')->label('Code postal'),
                    Forms\Components\TextInput::make('city')->label('Ville'),
                    Forms\Components\Select::make('country')->label('Pays')->default('FR')
                        ->options(['FR' => 'France', 'BE' => 'Belgique', 'LU' => 'Luxembourg', 'CH' => 'Suisse', 'DE' => 'Allemagne']),
                ]),
                Forms\Components\Section::make('Qualification collecte')->columns(3)->schema([
                    Forms\Components\TextInput::make('current_buyer')->label('Récupérateur actuel'),
                    Forms\Components\TextInput::make('estimated_grams_year')->label('Volume estimé (g/an)')->numeric(),
                    Forms\Components\Select::make('collection_frequency')->label('Fréquence')
                        ->options(['ponctuelle' => 'Ponctuelle', 'mensuelle' => 'Mensuelle', 'trimestrielle' => 'Trimestrielle', 'semestrielle' => 'Semestrielle', 'annuelle' => 'Annuelle']),
                    Forms\Components\Textarea::make('notes')->label('Notes')->rows(4)->columnSpanFull(),
                ]),
            ]),
            Forms\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                Forms\Components\Section::make('Suivi')->schema([
                    Forms\Components\Select::make('status')->label('Statut')->options(LeadStatus::class)->required()->default(LeadStatus::Nouveau->value),
                    Forms\Components\Select::make('assigned_to')->label('Commercial')
                        ->relationship('assignee', 'name', fn (Builder $query) => $query->where('is_active', true))
                        ->searchable()->preload()
                        ->default(fn () => Auth::user()->canManageTeam() ? null : Auth::id())
                        ->disabled(fn () => ! Auth::user()->canManageTeam())
                        ->dehydrated()
                        ->helperText('Vide = attribution automatique'),
                    Forms\Components\TextInput::make('source')->label('Source')->datalist(['Import fichier', 'Site web', 'Salon', 'Recommandation', 'Pages Jaunes', 'Annuaire Ordre']),
                    Forms\Components\DateTimePicker::make('next_action_at')->label('Prochaine action')->seconds(false)->native(false),
                    Forms\Components\TextInput::make('next_action')->label('Libellé prochaine action'),
                    Forms\Components\TagsInput::make('tags')->label('Étiquettes'),
                    Forms\Components\Toggle::make('do_not_call')->label('Ne plus appeler (opposition)'),
                ]),
            ]),
        ])->columns(3);
    }

    // ---------------------------------------------------------------- Tableau

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->deferLoading()
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('company_name')->label('Établissement')->searchable(['company_name', 'reference', 'contact_last_name', 'email'])->sortable()->weight('semibold')
                    ->description(fn (Lead $r) => $r->contact_name ?: $r->type?->getLabel())
                    ->limit(45)
                    ->tooltip(fn (Lead $r) => $r->company_name),
                Tables\Columns\TextColumn::make('city')->label('Ville')->searchable(['city', 'postal_code'])->sortable()
                    ->description(fn (Lead $r) => $r->postal_code),
                Tables\Columns\TextColumn::make('phone_normalized')->label('Téléphone')->searchable(['phone_normalized', 'mobile_normalized'])
                    ->formatStateUsing(fn ($state) => PhoneNormalizer::format($state))->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Statut')->badge()->sortable(),
                Tables\Columns\TextColumn::make('score')->label('Score IA')->sortable()->alignCenter()->badge()
                    ->color(fn ($state) => $state === null ? 'gray' : ($state >= 70 ? 'success' : ($state >= 40 ? 'warning' : 'danger')))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state),
                Tables\Columns\TextColumn::make('temperature')->label('Temp.')->badge()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('assignee.name')->label('Commercial')->sortable()->toggleable()
                    ->visible(fn () => Auth::user()->canSeeAllLeads()),
                Tables\Columns\TextColumn::make('call_attempts')->label('Appels')->alignCenter()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('last_contacted_at')->label('Dernier contact')->since()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('next_action_at')->label('Prochaine action')->dateTime('d/m H:i')->sortable()
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null)
                    ->description(fn (Lead $r) => $r->next_action ? Str::limit($r->next_action, 30) : null),
                Tables\Columns\TextColumn::make('source')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('department')->label('Dépt')->toggleable(isToggledHiddenByDefault: true)->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->date('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Statut')->options(LeadStatus::class)->multiple(),
                Tables\Filters\SelectFilter::make('type')->label('Type')->options(LeadType::class)->multiple(),
                Tables\Filters\SelectFilter::make('temperature')->label('Température')->options(LeadTemperature::class),
                Tables\Filters\SelectFilter::make('assigned_to')->label('Commercial')
                    ->relationship('assignee', 'name')->visible(fn () => Auth::user()->canSeeAllLeads()),
                Tables\Filters\SelectFilter::make('department')->label('Département')->multiple()
                    ->options(fn () => Lead::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department', 'department')->all()),
                Tables\Filters\SelectFilter::make('source')
                    ->options(fn () => Lead::query()->whereNotNull('source')->distinct()->pluck('source', 'source')->all()),
                Tables\Filters\Filter::make('a_traiter')->label('À appeler maintenant')->toggle()
                    ->query(fn (Builder $query) => $query->callQueue()),
                Tables\Filters\Filter::make('en_retard')->label('Action en retard')->toggle()
                    ->query(fn (Builder $query) => $query->open()->where('next_action_at', '<', now())),
                Tables\Filters\Filter::make('jamais_appele')->label('Jamais appelé')->toggle()
                    ->query(fn (Builder $query) => $query->where('call_attempts', 0)),
                Tables\Filters\Filter::make('non_attribue')->label('Non attribué')->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('assigned_to'))
                    ->visible(fn () => Auth::user()->canManageTeam()),
                Tables\Filters\Filter::make('score_min')->form([
                    Forms\Components\TextInput::make('min')->label('Score IA minimum')->numeric()->minValue(0)->maxValue(100),
                ])->query(fn (Builder $query, array $data) => $query->when($data['min'] ?? null, fn ($query, $min) => $query->where('score', '>=', $min))),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->filtersFormColumns(3)
            ->actions([
                LeadActions::call(Tables\Actions\Action::class)->iconButton()->tooltip('Appeler'),
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    LeadActions::logCall(Tables\Actions\Action::class),
                    LeadActions::changeStatus(Tables\Actions\Action::class),
                    LeadActions::followUp(Tables\Actions\Action::class),
                    LeadActions::note(Tables\Actions\Action::class),
                    LeadActions::aiScore(Tables\Actions\Action::class),
                    LeadActions::assign(Tables\Actions\Action::class),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()->visible(fn () => Auth::user()->canManageTeam()),
                    Tables\Actions\RestoreAction::make()->visible(fn () => Auth::user()->canManageTeam()),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('attribuer')
                        ->label('Attribuer à…')->icon('heroicon-o-user-plus')
                        ->visible(fn () => Auth::user()->canManageTeam())
                        ->form([
                            Forms\Components\Select::make('user_id')->label('Commercial')
                                ->options(fn () => User::salesAgents()->pluck('name', 'id'))
                                ->placeholder('Répartition automatique équilibrée'),
                        ])
                        ->action(function (EloquentCollection $records, array $data) {
                            $assigner = app(LeadAssigner::class);
                            $user = ! empty($data['user_id']) ? User::find($data['user_id']) : null;
                            $records->each(fn (Lead $l) => $assigner->assign($l, $user));
                            Notification::make()->title($records->count().' lead(s) attribué(s)')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('statut')
                        ->label('Changer le statut')->icon('heroicon-o-arrows-right-left')
                        ->form([Forms\Components\Select::make('status')->options(LeadStatus::class)->required()])
                        ->action(function (EloquentCollection $records, array $data) {
                            $status = $data['status'] instanceof LeadStatus ? $data['status'] : LeadStatus::from($data['status']);
                            $records->each(fn (Lead $l) => $l->changeStatus($status, 'Modification groupée'));
                            Notification::make()->title('Statuts mis à jour')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('iaScore')
                        ->label('Qualifier avec l\'IA')->icon('heroicon-o-sparkles')
                        ->visible(fn () => app(LeadIntelligence::class)->isAvailable())
                        ->requiresConfirmation()
                        ->modalDescription('Le scoring est lancé en tâche de fond (quelques secondes par lead).')
                        ->action(function (EloquentCollection $records) {
                            $records->each(fn (Lead $l) => ScoreLeadWithAi::dispatch($l->id));
                            Notification::make()->title($records->count().' lead(s) envoyés à l\'IA')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\ExportBulkAction::make()->exporter(LeadExporter::class),
                    Tables\Actions\DeleteBulkAction::make()->visible(fn () => Auth::user()->canManageTeam()),
                    Tables\Actions\RestoreBulkAction::make()->visible(fn () => Auth::user()->canManageTeam()),
                ]),
            ]);
    }

    // ---------------------------------------------------------------- Fiche

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Group::make()->columnSpan(['lg' => 2])->schema([
                Infolists\Components\Section::make()->columns(4)->schema([
                    Infolists\Components\TextEntry::make('status')->label('Statut')->badge(),
                    Infolists\Components\TextEntry::make('score')->label('Score IA')->badge()
                        ->color(fn ($state) => $state === null ? 'gray' : ($state >= 70 ? 'success' : ($state >= 40 ? 'warning' : 'danger')))
                        ->formatStateUsing(fn ($state) => $state === null ? 'Non évalué' : $state.'/100'),
                    Infolists\Components\TextEntry::make('assignee.name')->label('Commercial')->placeholder('Non attribué'),
                    Infolists\Components\TextEntry::make('next_action_at')->label('Prochaine action')->dateTime('d/m/Y H:i')
                        ->placeholder('—')->helperText(fn (Lead $r) => $r->next_action)
                        ->color(fn ($state) => $state?->isPast() ? 'danger' : null),
                ]),
                Infolists\Components\Section::make('Analyse IA')
                    ->icon('heroicon-o-sparkles')
                    ->visible(fn (Lead $r) => filled($r->ai_summary))
                    ->collapsible()
                    ->schema([
                        Infolists\Components\TextEntry::make('ai_summary')->label('Synthèse'),
                        Infolists\Components\TextEntry::make('ai_pitch')->label('Accroche conseillée')->extraAttributes(['class' => 'italic']),
                        Infolists\Components\TextEntry::make('ai_insights.objections')->label('Objections probables')->listWithLineBreaks()->bulleted(),
                        Infolists\Components\Grid::make(2)->schema([
                            Infolists\Components\TextEntry::make('ai_insights.best_time_to_call')->label('Meilleur créneau')->placeholder('—'),
                            Infolists\Components\TextEntry::make('ai_insights.next_action')->label('Action recommandée')->placeholder('—'),
                        ]),
                        Infolists\Components\TextEntry::make('ai_scored_at')->label('Analysé')->since()->size('sm')->color('gray'),
                    ]),
                Infolists\Components\Section::make('Qualification collecte')->columns(3)->schema([
                    Infolists\Components\TextEntry::make('current_buyer')->label('Récupérateur actuel')->placeholder('Inconnu'),
                    Infolists\Components\TextEntry::make('estimated_grams_year')->label('Volume estimé')->suffix(' g/an')->placeholder('Inconnu'),
                    Infolists\Components\TextEntry::make('collection_frequency')->label('Fréquence')->placeholder('—'),
                    Infolists\Components\TextEntry::make('notes')->label('Notes')->columnSpanFull()->placeholder('—')->markdown(),
                ]),
            ]),
            Infolists\Components\Group::make()->columnSpan(['lg' => 1])->schema([
                Infolists\Components\Section::make('Coordonnées')->schema([
                    Infolists\Components\TextEntry::make('type')->label('Type')->badge()->color('gray'),
                    Infolists\Components\TextEntry::make('contact_name')->label('Contact')
                        ->helperText(fn (Lead $r) => $r->contact_role)->placeholder('—'),
                    Infolists\Components\TextEntry::make('phone_normalized')->label('Téléphone')
                        ->formatStateUsing(fn ($state) => PhoneNormalizer::format($state))->copyable()->placeholder('—')
                        ->url(fn ($state) => $state ? 'tel:'.$state : null),
                    Infolists\Components\TextEntry::make('mobile_normalized')->label('Mobile')
                        ->formatStateUsing(fn ($state) => PhoneNormalizer::format($state))->copyable()->placeholder('—'),
                    Infolists\Components\TextEntry::make('email')->label('Email')->copyable()->placeholder('—')
                        ->url(fn ($state) => $state ? 'mailto:'.$state : null),
                    Infolists\Components\TextEntry::make('address')->label('Adresse')
                        ->formatStateUsing(fn (Lead $r) => trim($r->address."\n".$r->postal_code.' '.$r->city))
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('website')->label('Site')->url(fn ($state) => $state)->openUrlInNewTab()->placeholder('—'),
                ]),
                Infolists\Components\Section::make('Suivi')->schema([
                    Infolists\Components\TextEntry::make('reference')->label('Réf.'),
                    Infolists\Components\TextEntry::make('call_attempts')->label('Tentatives d\'appel'),
                    Infolists\Components\TextEntry::make('last_contacted_at')->label('Dernier contact')->since()->placeholder('Jamais'),
                    Infolists\Components\TextEntry::make('source')->placeholder('—'),
                    Infolists\Components\TextEntry::make('tags')->label('Étiquettes')->badge()->placeholder('—'),
                    Infolists\Components\IconEntry::make('do_not_call')->label('Opposition démarchage')->boolean()
                        ->trueColor('danger')->falseColor('gray'),
                    Infolists\Components\TextEntry::make('created_at')->label('Créé le')->dateTime('d/m/Y H:i'),
                ]),
            ]),
        ])->columns(3);
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->canManageTeam() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->canManageTeam() ?? false;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ActivitiesRelationManager::class,
            RelationManagers\CallsRelationManager::class,
            RelationManagers\TasksRelationManager::class,
            RelationManagers\CollectionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'view' => Pages\ViewLead::route('/{record}'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }
}
