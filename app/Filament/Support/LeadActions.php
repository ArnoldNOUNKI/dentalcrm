<?php

namespace App\Filament\Support;

use App\Enums\ActivityType;
use App\Enums\CallOutcome;
use App\Enums\LeadStatus;
use App\Enums\TaskType;
use App\Models\Lead;
use App\Models\TelephonyProvider;
use App\Models\User;
use App\Services\Ai\LeadIntelligence;
use App\Services\CallLogger;
use App\Services\LeadAssigner;
use App\Services\PhoneNormalizer;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Actions réutilisables sur un lead (tableau, fiche, file d'appels).
 * Chaque méthode reçoit la classe d'action à instancier :
 *   - Filament\Tables\Actions\Action  (lignes de tableau)
 *   - Filament\Actions\Action         (en-têtes de page)
 */
class LeadActions
{
    /** @param class-string $class */
    public static function call(string $class, string $name = 'appeler')
    {
        return $class::make($name)
            ->label('Appeler')
            ->icon('heroicon-o-phone')
            ->color('success')
            ->visible(fn (Lead $record) => $record->dial_number && ! $record->do_not_call)
            // Sans service de téléphonie avec click-to-call : simple lien tel: (softphone / mobile)
            ->url(fn (Lead $record) => static::clickToCallProvider() ? null : 'tel:'.$record->dial_number)
            ->requiresConfirmation(fn () => (bool) static::clickToCallProvider())
            ->modalHeading(fn (Lead $record) => 'Appeler '.$record->company_name)
            ->modalDescription(fn (Lead $record) => 'Via '.static::clickToCallProvider()?->name.' : votre poste va sonner, puis le '
                .PhoneNormalizer::format($record->dial_number).' sera composé dès que vous décrochez.')
            ->modalSubmitActionLabel('Lancer l\'appel')
            ->action(function (Lead $record) {
                $provider = static::clickToCallProvider();

                try {
                    $message = $provider->driver()->clickToCall($provider, Auth::user(), $provider->agentFor(Auth::user()), $record->dial_number);
                    Notification::make()->title('Appel en cours…')->body($message)->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('Échec du click-to-call ('.$provider?->name.')')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Saisie / qualification d'un appel (complète le dernier appel récupéré du service de téléphonie s'il existe). */
    public static function logCall(string $class, string $name = 'qualifierAppel')
    {
        return $class::make($name)
            ->label('Qualifier l\'appel')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('warning')
            ->modalWidth('2xl')
            ->form([
                Forms\Components\ToggleButtons::make('outcome')
                    ->label('Issue de l\'appel')
                    ->options(CallOutcome::class)
                    ->inline()
                    ->required(),
                Forms\Components\Textarea::make('agent_notes')
                    ->label('Notes de l\'appel')
                    ->placeholder('Interlocuteur, besoins, volume de déchets, concurrent actuel, objections…')
                    ->rows(5),
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('duration_min')->label('Durée (min)')->numeric()->minValue(0)
                        ->helperText('Ignoré si l\'appel a été récupéré automatiquement'),
                    Forms\Components\DateTimePicker::make('follow_up_at')->label('Rappeler le')->seconds(false)->native(false)->minDate(now()->subMinute()),
                    Forms\Components\Toggle::make('ai')->label('Analyse IA')->default(fn () => app(LeadIntelligence::class)->isAvailable())->inline(false),
                ]),
            ])
            ->action(function (Lead $record, array $data) {
                app(CallLogger::class)->log(
                    $record,
                    $data['outcome'] instanceof CallOutcome ? $data['outcome'] : CallOutcome::from($data['outcome']),
                    $data['agent_notes'] ?? null,
                    ! empty($data['follow_up_at']) ? Carbon::parse($data['follow_up_at']) : null,
                    (bool) ($data['ai'] ?? false),
                    (float) ($data['duration_min'] ?? 0),
                );

                Notification::make()->title('Appel enregistré')->success()->send();
            });
    }

    public static function changeStatus(string $class, string $name = 'statut')
    {
        return $class::make($name)
            ->label('Changer le statut')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->fillForm(fn (Lead $record) => ['status' => $record->status])
            ->form([
                Forms\Components\Select::make('status')->label('Nouveau statut')->options(LeadStatus::class)->required()->live(),
                Forms\Components\TextInput::make('lost_reason')->label('Raison')
                    ->visible(fn (Get $get) => in_array($get('status'), [LeadStatus::NonInteresse->value, LeadStatus::NonInteresse, LeadStatus::Invalide->value, LeadStatus::Invalide], true))
                    ->datalist(['Déjà un récupérateur', 'Pas assez de volume', 'Cabinet fermé / retraite', 'Ne veut pas être rappelé', 'Hors cible']),
                Forms\Components\Textarea::make('comment')->label('Commentaire')->rows(2),
            ])
            ->action(function (Lead $record, array $data) {
                $status = $data['status'] instanceof LeadStatus ? $data['status'] : LeadStatus::from($data['status']);
                if (! empty($data['lost_reason'])) {
                    $record->forceFill(['lost_reason' => $data['lost_reason']])->saveQuietly();
                }
                $record->changeStatus($status, $data['comment'] ?? $data['lost_reason'] ?? null);
                Notification::make()->title('Statut mis à jour')->success()->send();
            });
    }

    public static function followUp(string $class, string $name = 'rappel')
    {
        return $class::make($name)
            ->label('Planifier un rappel')
            ->icon('heroicon-o-bell-alert')
            ->color('info')
            ->form([
                Forms\Components\DateTimePicker::make('due_at')->label('Date et heure')->seconds(false)->native(false)->required()
                    ->default(now()->addDay()->setTime(10, 0)),
                Forms\Components\TextInput::make('title')->label('Objet')->required()->default('Rappeler le prospect'),
                Forms\Components\Select::make('type')->options(TaskType::class)->default(TaskType::Rappel->value)->required(),
                Forms\Components\Select::make('user_id')->label('Pour')
                    ->options(fn () => User::where('is_active', true)->pluck('name', 'id'))
                    ->default(fn () => Auth::id())
                    ->visible(fn () => Auth::user()->canManageTeam()),
            ])
            ->action(function (Lead $record, array $data) {
                $record->scheduleFollowUp(Carbon::parse($data['due_at']), $data['title'], $data['user_id'] ?? Auth::id(), TaskType::from($data['type'] instanceof TaskType ? $data['type']->value : $data['type']));
                Notification::make()->title('Rappel planifié')->success()->send();
            });
    }

    public static function note(string $class, string $name = 'note')
    {
        return $class::make($name)
            ->label('Ajouter une note')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->form([Forms\Components\Textarea::make('body')->label('Note')->required()->rows(4)])
            ->action(function (Lead $record, array $data) {
                $record->logActivity(ActivityType::Note, 'Note', $data['body']);
                Notification::make()->title('Note ajoutée')->success()->send();
            });
    }

    public static function assign(string $class, string $name = 'attribuer')
    {
        return $class::make($name)
            ->label('Attribuer')
            ->icon('heroicon-o-user-plus')
            ->color('gray')
            ->visible(fn () => Auth::user()->canManageTeam())
            ->form([
                Forms\Components\Select::make('user_id')->label('Commercial')
                    ->options(fn () => User::salesAgents()->pluck('name', 'id'))->required(),
            ])
            ->action(function (Lead $record, array $data) {
                app(LeadAssigner::class)->assign($record, User::findOrFail($data['user_id']));
                Notification::make()->title('Lead attribué')->success()->send();
            });
    }

    // ------------------------------------------------------------------ IA

    public static function aiScore(string $class, string $name = 'iaScore')
    {
        return $class::make($name)
            ->label('Qualifier avec l\'IA')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->visible(fn () => app(LeadIntelligence::class)->isAvailable())
            ->action(function (Lead $record) {
                try {
                    $r = app(LeadIntelligence::class)->scoreLead($record);
                    Notification::make()->title("Score IA : {$r['score']}/100")->body($r['summary'] ?? null)->success()->persistent()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('Erreur IA')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function aiEmail(string $class, string $name = 'iaEmail')
    {
        return $class::make($name)
            ->label('Email (IA)')
            ->icon('heroicon-o-envelope')
            ->color('info')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Valider')
            ->form([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\Select::make('purpose')->label('Objectif')->required()->default('presentation')->options([
                        'presentation' => 'Présentation du service',
                        'relance' => 'Relance',
                        'kit' => 'Envoi du kit de collecte',
                        'offre' => 'Offre de rachat',
                        'reactivation' => 'Réactivation client',
                    ]),
                    Forms\Components\TextInput::make('instructions')->label('Consignes (optionnel)')->placeholder('ex : mentionner notre passage dans le 57 la semaine prochaine'),
                ]),
                Forms\Components\TextInput::make('subject')->label('Objet')->required(),
                Forms\Components\Textarea::make('body')->label('Message')->required()->rows(12)
                    ->hintAction(
                        Forms\Components\Actions\Action::make('generer')
                            ->label('Générer avec l\'IA')
                            ->icon('heroicon-o-sparkles')
                            ->visible(fn () => app(LeadIntelligence::class)->isAvailable())
                            ->action(function (Get $get, Set $set, $record) {
                                try {
                                    $mail = app(LeadIntelligence::class)->draftEmail($record, $get('purpose') ?? 'presentation', $get('instructions'), Auth::user());
                                    $set('subject', $mail['subject']);
                                    $set('body', $mail['body']);
                                } catch (\Throwable $e) {
                                    Notification::make()->title('Erreur IA')->body($e->getMessage())->danger()->send();
                                }
                            })
                    ),
                Forms\Components\Toggle::make('send')->label(fn ($record) => $record?->email ? 'Envoyer à '.$record->email : 'Pas d\'email : sera seulement enregistré dans l\'historique')
                    ->default(fn ($record) => (bool) $record?->email)
                    ->disabled(fn ($record) => ! $record?->email),
            ])
            ->action(function (Lead $record, array $data) {
                $sent = false;
                if (($data['send'] ?? false) && $record->email) {
                    try {
                        $user = Auth::user();
                        Mail::raw($data['body'], function ($m) use ($record, $data, $user) {
                            $m->to($record->email)->subject($data['subject'])->replyTo($user->email, $user->name);
                        });
                        $sent = true;
                    } catch (\Throwable $e) {
                        Notification::make()->title('Échec d\'envoi')->body($e->getMessage())->danger()->send();
                    }
                }

                $record->logActivity(ActivityType::Email, ($sent ? 'Email envoyé : ' : 'Brouillon email : ').$data['subject'], $data['body'], ['sent' => $sent]);

                if ($sent) {
                    $record->forceFill(['last_contacted_at' => now()])->saveQuietly();
                }

                Notification::make()->title($sent ? 'Email envoyé' : 'Email enregistré')->success()->send();
            });
    }

    public static function aiAsk(string $class, string $name = 'iaAsk')
    {
        return $class::make($name)
            ->label('Demander à l\'IA')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('primary')
            ->visible(fn () => app(LeadIntelligence::class)->isAvailable())
            ->form([
                Forms\Components\Textarea::make('question')->label('Votre question')->required()->rows(3)
                    ->placeholder('Ex : Comment relancer ce labo qui travaille déjà avec un concurrent ? Prépare-moi les 3 questions à poser.'),
            ])
            ->action(function (Lead $record, array $data) {
                try {
                    $answer = app(LeadIntelligence::class)->ask($record, $data['question']);
                    $record->logActivity(ActivityType::Ai, 'Question à l\'IA : '.Str::limit($data['question'], 120), $answer);
                    Notification::make()->title('Réponse de l\'IA')
                        ->body(new HtmlString(Str::markdown($answer, ['html_input' => 'escape', 'allow_unsafe_links' => false])))
                        ->persistent()->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('Erreur IA')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function clickToCallProvider(): ?TelephonyProvider
    {
        return once(fn () => TelephonyProvider::forClickToCall());
    }
}
