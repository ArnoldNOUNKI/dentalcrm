<?php

namespace App\Filament\Widgets;

use App\Services\Ai\LeadIntelligence;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class AiBriefing extends Widget
{
    protected static string $view = 'filament.widgets.ai-briefing';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    public ?string $briefing = null;

    public static function canView(): bool
    {
        return app(LeadIntelligence::class)->isAvailable();
    }

    public function mount(): void
    {
        $this->briefing = Cache::get($this->cacheKey());
    }

    public function generate(LeadIntelligence $ai): void
    {
        try {
            $this->briefing = $ai->dailyBriefing(Auth::user());
            Cache::put($this->cacheKey(), $this->briefing, now()->endOfDay());
        } catch (\Throwable $e) {
            Notification::make()->title('Erreur IA')->body($e->getMessage())->danger()->send();
        }
    }

    public function getBriefingHtml(): ?HtmlString
    {
        return $this->briefing
            ? new HtmlString(Str::markdown($this->briefing, ['html_input' => 'escape', 'allow_unsafe_links' => false]))
            : null;
    }

    private function cacheKey(): string
    {
        return 'briefing:'.Auth::id().':'.today()->toDateString();
    }
}
