<?php

namespace App\Filament\Resources\LeadResource\RelationManagers;

use App\Enums\ActivityType;
use App\Models\Activity;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Historique';

    protected static ?string $icon = 'heroicon-o-clock';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->activities()->count();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Textarea::make('body')->label('Note')->required()->rows(4)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->poll('20s')
            ->columns([
                Tables\Columns\IconColumn::make('type')->label('')->icon(fn (ActivityType $state) => $state->getIcon())
                    ->color(fn (ActivityType $state) => $state->getColor())->width('1%'),
                Tables\Columns\TextColumn::make('subject')->label('Événement')->weight('medium')->wrap()
                    ->description(fn (Activity $r) => $r->body ? Str::limit($r->body, 220) : null),
                Tables\Columns\TextColumn::make('user.name')->label('Par')->placeholder('Système'),
                Tables\Columns\TextColumn::make('occurred_at')->label('Quand')->dateTime('d/m/Y H:i')->sortable()
                    ->description(fn (Activity $r) => $r->occurred_at->diffForHumans()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(ActivityType::class)->multiple(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Ajouter une note')
                    ->mutateFormDataUsing(fn (array $data) => $data + [
                        'type' => ActivityType::Note,
                        'subject' => 'Note',
                        'user_id' => Auth::id(),
                        'occurred_at' => now(),
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton()
                    ->infolist(fn () => [
                        TextEntry::make('subject')->label('Événement'),
                        TextEntry::make('body')->label('Détail')->markdown()->placeholder('—'),
                        TextEntry::make('meta.coaching_tip')->label('Conseil IA')->placeholder('—')
                            ->visible(fn (Activity $r) => filled($r->meta['coaching_tip'] ?? null)),
                    ]),
                Tables\Actions\DeleteAction::make()->iconButton()
                    ->visible(fn (Activity $r) => $r->type === ActivityType::Note && ($r->user_id === Auth::id() || Auth::user()->canManageTeam())),
            ]);
    }
}
