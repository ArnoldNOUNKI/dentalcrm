<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-sparkles" icon-color="primary">
        <x-slot name="heading">Briefing IA du jour</x-slot>
        <x-slot name="headerEnd">
            <x-filament::button size="xs" color="gray" wire:click="generate" wire:loading.attr="disabled" icon="heroicon-m-arrow-path">
                <span wire:loading.remove wire:target="generate">{{ $briefing ? 'Régénérer' : 'Générer' }}</span>
                <span wire:loading wire:target="generate">Analyse…</span>
            </x-filament::button>
        </x-slot>

        @if ($html = $this->getBriefingHtml())
            <div class="fi-prose prose prose-sm max-w-none dark:prose-invert" style="max-height: 340px; overflow-y: auto;">
                {!! $html !!}
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Vos priorités du jour, l'ordre d'appel conseillé et un conseil de pitch, générés à partir de vos rappels et de vos leads chauds.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
