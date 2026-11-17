<x-filament-panels::page>
    <style>
        .pl-toolbar { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; }
        .pl-board { display:flex; gap:1rem; overflow-x:auto; padding-bottom:1rem; align-items:flex-start; }
        .pl-col { flex: 0 0 280px; border-radius:.75rem; background: rgba(127,127,127,.07); padding:.6rem; min-height: 200px; transition: background .15s; }
        .pl-col.is-over { background: rgba(var(--primary-500), .15); outline: 2px dashed rgb(var(--primary-500)); }
        .pl-col-head { display:flex; justify-content:space-between; align-items:center; padding:.2rem .3rem .6rem; font-weight:600; font-size:.875rem; }
        .pl-cards { display:flex; flex-direction:column; gap:.5rem; max-height: calc(100vh - 290px); overflow-y:auto; }
        .pl-card { background: white; border-radius:.6rem; padding:.6rem .7rem; box-shadow: 0 1px 2px rgba(0,0,0,.08); border:1px solid rgba(127,127,127,.15); cursor: grab; font-size:.82rem; }
        .dark .pl-card { background: rgb(31 41 55); }
        .pl-card:active { cursor: grabbing; }
        .pl-card a { font-weight:600; font-size:.875rem; }
        .pl-meta { display:flex; justify-content:space-between; gap:.4rem; margin-top:.35rem; color: rgb(107 114 128); font-size:.75rem; }
        .pl-overdue { color: rgb(var(--danger-600)); font-weight:600; }
        .pl-more { text-align:center; font-size:.75rem; color: rgb(107 114 128); padding:.4rem; }
    </style>

    <div class="pl-toolbar">
        <div style="min-width:260px">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" placeholder="Rechercher (nom, ville, CP)…" wire:model.live.debounce.400ms="search" />
            </x-filament::input.wrapper>
        </div>
        @if (count($this->agents))
            <div style="min-width:220px">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="userFilter">
                        <option value="">Toute l'équipe</option>
                        @foreach ($this->agents as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        @endif
        <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;">
            <x-filament::input.checkbox wire:model.live="showClosed" /> Afficher les perdus
        </label>
        <span wire:loading style="font-size:.8rem;color:rgb(107 114 128)">Chargement…</span>
    </div>

    <div class="pl-board"
         x-data="{
            dragging: null,
            drop(status, el) {
                el.classList.remove('is-over');
                if (this.dragging) { $wire.moveLead(this.dragging, status); }
                this.dragging = null;
            }
         }">
        @foreach ($this->columns as $status => $col)
            @php($st = \App\Enums\LeadStatus::from($status))
            <div class="pl-col"
                 wire:key="col-{{ $status }}"
                 x-on:dragover.prevent="$el.classList.add('is-over')"
                 x-on:dragleave="$el.classList.remove('is-over')"
                 x-on:drop.prevent="drop('{{ $status }}', $el)">
                <div class="pl-col-head">
                    <span style="display:flex;align-items:center;gap:.4rem;">
                        <x-filament::icon :icon="$st->getIcon()" style="width:1rem;height:1rem;" />
                        {{ $st->getLabel() }}
                    </span>
                    <x-filament::badge :color="$st->getColor()" size="sm">{{ $col['total'] }}</x-filament::badge>
                </div>

                <div class="pl-cards">
                    @foreach ($col['leads'] as $lead)
                        <div class="pl-card" draggable="true" wire:key="lead-{{ $lead->id }}"
                             x-on:dragstart="dragging = {{ $lead->id }}"
                             x-on:dragend="dragging = dragging">
                            <a href="{{ $this->leadUrl($lead->id) }}" wire:navigate>{{ $lead->company_name }}</a>
                            <div class="pl-meta">
                                <span>{{ $lead->city ?: '—' }}</span>
                                @if (! is_null($lead->score))
                                    <x-filament::badge size="sm" :color="$lead->score >= 70 ? 'success' : ($lead->score >= 40 ? 'warning' : 'danger')">{{ $lead->score }}</x-filament::badge>
                                @endif
                            </div>
                            <div class="pl-meta">
                                <span>{{ $lead->assignee?->name ?? 'Non attribué' }}</span>
                                @if ($lead->next_action_at)
                                    <span class="{{ $lead->next_action_at->isPast() ? 'pl-overdue' : '' }}" title="{{ $lead->next_action }}">⏰ {{ $lead->next_action_at->format('d/m H:i') }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @if ($col['total'] > $col['leads']->count())
                        <div class="pl-more">+ {{ $col['total'] - $col['leads']->count() }} autres (voir la liste des leads)</div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
