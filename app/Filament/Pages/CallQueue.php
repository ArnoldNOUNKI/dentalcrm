<?php

namespace App\Filament\Pages;

use App\Enums\CallOutcome;
use App\Filament\Resources\LeadResource;
use App\Filament\Support\LeadActions;
use App\Models\Call;
use App\Models\Lead;
use App\Services\CallLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Session d'appels (« power dialer ») : enchaîne les leads à appeler du commercial,
 * avec pitch IA, objections, historique et qualification en un clic.
 */
class CallQueue extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-play-circle';

    protected static ?string $navigationGroup = 'Téléphonie';

    protected static ?string $navigationLabel = 'Session d\'appels';

    protected static ?string $title = 'Session d\'appels';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.call-queue';

    public ?int $leadId = null;

    public string $notes = '';

    public ?string $followUpAt = null;

    /** @var array<int> */
    public array $skipped = [];

    public int $doneThisSession = 0;

    public ?string $startedAt = null;

    public static function getNavigationBadge(): ?string
    {
        $n = static::queueQuery()->count();

        return $n ? (string) $n : null;
    }

    public static function queueQuery()
    {
        return Lead::query()->where('assigned_to', Auth::id())->callQueue();
    }

    public function mount(): void
    {
        $this->startedAt = now()->toDateTimeString();
        $this->loadNext();
    }

    public function getLeadProperty(): ?Lead
    {
        return $this->leadId ? Lead::with(['activities' => fn ($q) => $q->limit(8), 'activities.user'])->find($this->leadId) : null;
    }

    public function getRemainingProperty(): int
    {
        return static::queueQuery()->whereNotIn('id', $this->skipped)->count();
    }

    public function getCallsTodayProperty(): int
    {
        return Call::where('user_id', Auth::id())->where('started_at', '>=', today())->count();
    }

    public function loadNext(): void
    {
        $this->leadId = static::queueQuery()
            ->whereNotIn('id', $this->skipped)
            ->when($this->leadId, fn ($q) => $q->whereKeyNot($this->leadId))
            ->value('id');

        $this->notes = '';
        $this->followUpAt = null;
    }

    public function outcome(string $value): void
    {
        $lead = $this->lead;
        $outcome = CallOutcome::tryFrom($value);

        if (! $lead || ! $outcome) {
            return;
        }

        $followUp = $this->followUpAt ? Carbon::parse($this->followUpAt) : null;

        if ($outcome === CallOutcome::Rappel && ! $followUp) {
            Notification::make()->title('Indiquez la date de rappel')->warning()->send();

            return;
        }

        app(CallLogger::class)->log($lead, $outcome, $this->notes ?: null, $followUp, runAi: true);

        $this->doneThisSession++;
        Notification::make()->title($lead->company_name.' : '.$outcome->getLabel())->success()->send();

        $this->loadNext();
    }

    public function skip(): void
    {
        if ($this->leadId) {
            $this->skipped[] = $this->leadId;
        }

        $this->loadNext();
    }

    // ---- Actions Filament réutilisées (bouton Appeler, email IA, etc.)

    public function appelerAction(): Action
    {
        return LeadActions::call(Action::class)->record(fn () => $this->lead)->size('lg');
    }

    public function iaEmailAction(): Action
    {
        return LeadActions::aiEmail(Action::class)->record(fn () => $this->lead);
    }

    public function iaAskAction(): Action
    {
        return LeadActions::aiAsk(Action::class)->record(fn () => $this->lead);
    }

    public function noteAction(): Action
    {
        return LeadActions::note(Action::class)->record(fn () => $this->lead);
    }

    public function iaScoreAction(): Action
    {
        return LeadActions::aiScore(Action::class)->record(fn () => $this->lead);
    }

    public function ficheAction(): Action
    {
        return Action::make('fiche')->label('Fiche complète')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
            ->url(fn () => $this->leadId ? LeadResource::getUrl('view', ['record' => $this->leadId]) : null)
            ->openUrlInNewTab();
    }
}
