<?php

namespace App\Filament\Resources;

use App\Enums\CallOutcome;
use App\Filament\Resources\CallResource\Pages;
use App\Jobs\SummarizeCallWithAi;
use App\Models\Call;
use App\Models\Lead;
use App\Services\Ai\LeadIntelligence;
use App\Services\PhoneNormalizer;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class CallResource extends Resource
{
    protected static ?string $model = Call::class;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationGroup = 'Téléphonie';

    protected static ?string $modelLabel = 'appel';

    protected static ?string $navigationLabel = 'Journal d\'appels';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo()->with(['lead', 'user']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                Tables\Columns\IconColumn::make('direction')->label('')
                    ->icon(fn (string $state, Call $r) => $state === 'in'
                        ? ($r->is_answered ? 'heroicon-o-phone-arrow-down-left' : 'heroicon-o-phone-x-mark')
                        : ($r->is_answered ? 'heroicon-o-phone-arrow-up-right' : 'heroicon-o-phone-x-mark'))
                    ->color(fn (Call $r) => $r->is_answered ? 'success' : 'danger')
                    ->tooltip(fn (Call $r) => ($r->direction === 'in' ? 'Entrant' : 'Sortant').($r->is_answered ? ' décroché' : ' non décroché')),
                Tables\Columns\TextColumn::make('started_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('lead.company_name')->label('Lead')->searchable()->placeholder('Non rattaché')
                    ->url(fn (Call $r) => $r->lead_id ? LeadResource::getUrl('view', ['record' => $r->lead_id]) : null),
                Tables\Columns\TextColumn::make('number')->label('Numéro')
                    ->state(fn (Call $r) => PhoneNormalizer::format($r->direction === 'in' ? $r->from_number : $r->to_number))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($qq) => $qq->where('from_number', 'like', '%'.preg_replace('/\D/', '', $search).'%')->orWhere('to_number', 'like', '%'.preg_replace('/\D/', '', $search).'%')))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')->label('Agent')->toggleable()->placeholder('—'),
                Tables\Columns\TextColumn::make('duration_label')->label('Durée')->alignCenter(),
                Tables\Columns\TextColumn::make('outcome')->label('Issue')->badge()->placeholder('À qualifier'),
                Tables\Columns\TextColumn::make('ai_sentiment')->label('Sentiment')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'positif' => 'success', 'negatif' => 'danger', default => 'gray'
                    })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('ai_summary')->label('Résumé IA')->limit(80)->wrap()->toggleable()->placeholder('—'),
                Tables\Columns\IconColumn::make('recording_url')->label('Enr.')->boolean()
                    ->state(fn (Call $r) => filled($r->recording_url))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('direction')->options(['in' => 'Entrant', 'out' => 'Sortant']),
                Tables\Filters\TernaryFilter::make('is_answered')->label('Décroché'),
                Tables\Filters\SelectFilter::make('outcome')->label('Issue')->options(CallOutcome::class)->multiple(),
                Tables\Filters\SelectFilter::make('user_id')->label('Agent')->relationship('user', 'name')
                    ->visible(fn () => Auth::user()->canSeeAllLeads()),
                Tables\Filters\Filter::make('a_qualifier')->label('À qualifier')->toggle()->query(fn (Builder $query) => $query->whereNull('outcome')),
                Tables\Filters\Filter::make('non_rattache')->label('Sans lead')->toggle()->query(fn (Builder $query) => $query->whereNull('lead_id')),
                Tables\Filters\Filter::make('aujourdhui')->label('Aujourd\'hui')->toggle()->query(fn (Builder $query) => $query->where('started_at', '>=', today())),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('qualifier')
                        ->label('Qualifier / notes')
                        ->icon('heroicon-o-clipboard-document-check')
                        ->fillForm(fn (Call $r) => ['outcome' => $r->outcome, 'agent_notes' => $r->agent_notes, 'transcript' => $r->transcript])
                        ->form([
                            Forms\Components\Select::make('outcome')->label('Issue')->options(CallOutcome::class),
                            Forms\Components\Textarea::make('agent_notes')->label('Notes')->rows(4),
                            Forms\Components\Textarea::make('transcript')->label('Transcription (coller si besoin)')->rows(6),
                        ])
                        ->action(function (Call $r, array $data) {
                            $r->update($data);
                            if ($r->hasContentForAi() && $r->lead_id) {
                                SummarizeCallWithAi::dispatch($r->id, force: true);
                            }
                            Notification::make()->title('Appel mis à jour')->success()->send();
                        }),
                    Tables\Actions\Action::make('rattacher')
                        ->label('Rattacher à un lead')
                        ->icon('heroicon-o-link')
                        ->visible(fn (Call $r) => ! $r->lead_id)
                        ->form([
                            Forms\Components\Select::make('lead_id')->label('Lead')->searchable()->required()
                                ->getSearchResultsUsing(fn (string $search) => Lead::query()->visibleTo()
                                    ->where(fn ($q) => $q->where('company_name', 'like', "%{$search}%")->orWhere('phone_normalized', 'like', '%'.preg_replace('/\D/', '', $search).'%'))
                                    ->limit(20)->pluck('company_name', 'id')),
                        ])
                        ->action(fn (Call $r, array $data) => $r->update(['lead_id' => $data['lead_id']])),
                    Tables\Actions\Action::make('ia')
                        ->label('Analyser avec l\'IA')
                        ->icon('heroicon-o-sparkles')
                        ->visible(fn (Call $r) => $r->lead_id && $r->hasContentForAi() && app(LeadIntelligence::class)->isAvailable())
                        ->action(function (Call $r) {
                            try {
                                app(LeadIntelligence::class)->summarizeCall($r);
                                Notification::make()->title('Analyse IA terminée')->success()->send();
                            } catch (\Throwable $e) {
                                Notification::make()->title('Erreur IA')->body($e->getMessage())->danger()->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('lead.company_name')->label('Lead')->placeholder('Non rattaché')
                    ->url(fn (Call $r) => $r->lead_id ? LeadResource::getUrl('view', ['record' => $r->lead_id]) : null),
                Infolists\Components\TextEntry::make('started_at')->label('Date')->dateTime('d/m/Y H:i'),
                Infolists\Components\TextEntry::make('duration_label')->label('Durée'),
                Infolists\Components\TextEntry::make('outcome')->label('Issue')->badge()->placeholder('À qualifier'),
                Infolists\Components\TextEntry::make('user.name')->label('Agent')->placeholder('—'),
                Infolists\Components\TextEntry::make('direction')->label('Sens')->formatStateUsing(fn ($state) => $state === 'in' ? 'Entrant' : 'Sortant'),
                Infolists\Components\TextEntry::make('from_number')->label('De')->formatStateUsing(fn ($state) => PhoneNormalizer::format($state)),
                Infolists\Components\TextEntry::make('to_number')->label('Vers')->formatStateUsing(fn ($state) => PhoneNormalizer::format($state)),
            ]),
            Infolists\Components\Section::make('Enregistrement')
                ->visible(fn (Call $r) => filled($r->recording_url))
                ->schema([
                    Infolists\Components\TextEntry::make('recording_url')->hiddenLabel()
                        ->formatStateUsing(fn ($state) => new HtmlString('<audio controls preload="none" style="width:100%" src="'.e($state).'"></audio>')),
                ]),
            Infolists\Components\Section::make('Analyse IA')->icon('heroicon-o-sparkles')
                ->visible(fn (Call $r) => filled($r->ai_summary))
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('ai_summary')->label('Résumé')->columnSpanFull()
                        ->formatStateUsing(fn ($state) => new HtmlString(nl2br(e($state)))),
                    Infolists\Components\TextEntry::make('ai_next_step')->label('Prochaine étape'),
                    Infolists\Components\TextEntry::make('ai_sentiment')->label('Sentiment')->badge(),
                    Infolists\Components\TextEntry::make('ai_extracted.interest_score')->label('Intérêt')->suffix('/100')->placeholder('—'),
                    Infolists\Components\TextEntry::make('ai_extracted.decision_maker')->label('Décideur')->placeholder('—'),
                    Infolists\Components\TextEntry::make('ai_extracted.objections')->label('Objections')->listWithLineBreaks()->bulleted()->placeholder('—'),
                    Infolists\Components\TextEntry::make('ai_extracted.coaching_tip')->label('Conseil coaching')->placeholder('—')->color('warning'),
                ]),
            Infolists\Components\Section::make('Notes de l\'agent')->visible(fn (Call $r) => filled($r->agent_notes))
                ->schema([Infolists\Components\TextEntry::make('agent_notes')->hiddenLabel()]),
            Infolists\Components\Section::make('Transcription')->collapsed()->visible(fn (Call $r) => filled($r->transcript))
                ->schema([Infolists\Components\TextEntry::make('transcript')->hiddenLabel()
                    ->formatStateUsing(fn ($state) => new HtmlString(nl2br(e($state))))]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCalls::route('/'),
            'view' => Pages\ViewCall::route('/{record}'),
        ];
    }
}
