<x-filament-panels::page>
    <style>
        .cq-grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
        @media (min-width: 1024px) { .cq-grid { grid-template-columns: 2fr 1fr; } }
        .cq-stats { display: grid; gap: 1rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .cq-kv { display: grid; grid-template-columns: 140px 1fr; gap: .35rem 1rem; font-size: .875rem; }
        .cq-kv dt { color: rgb(107 114 128); }
        .cq-outcomes { display: flex; flex-wrap: wrap; gap: .5rem; }
        .cq-pitch { border-left: 3px solid rgb(var(--primary-500)); padding: .5rem .9rem; font-style: italic; background: rgba(var(--primary-500), .06); border-radius: .25rem; }
        .cq-big { font-size: 1.75rem; font-weight: 700; line-height: 1.2; }
        .cq-muted { color: rgb(107 114 128); font-size: .8rem; }
        .cq-textarea { width: 100%; border-radius: .5rem; border: 1px solid rgb(209 213 219); padding: .6rem .75rem; font-size: .9rem; background: transparent; }
        .dark .cq-textarea { border-color: rgb(55 65 81); }
        .cq-input { border-radius: .5rem; border: 1px solid rgb(209 213 219); padding: .45rem .6rem; font-size: .9rem; background: transparent; }
        .dark .cq-input { border-color: rgb(55 65 81); color-scheme: dark; }
        .cq-timeline li { padding: .5rem 0; border-bottom: 1px dashed rgba(127,127,127,.25); font-size: .85rem; }
    </style>

    <div class="cq-stats">
        <x-filament::section compact>
            <div class="cq-muted">Restants dans ma file</div>
            <div class="cq-big">{{ $this->remaining }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="cq-muted">Qualifiés cette session</div>
            <div class="cq-big">{{ $doneThisSession }}</div>
        </x-filament::section>
        <x-filament::section compact>
            <div class="cq-muted">Mes appels aujourd'hui / objectif</div>
            <div class="cq-big">{{ $this->callsToday }} <span class="cq-muted">/ {{ auth()->user()->daily_call_target }}</span></div>
        </x-filament::section>
    </div>

    @php($lead = $this->lead)

    @if (! $lead)
        <x-filament::section>
            <div style="text-align:center; padding: 3rem 1rem;">
                <x-filament::icon icon="heroicon-o-check-badge" style="width:3rem;height:3rem;margin:0 auto;color:rgb(var(--success-500))" />
                <h2 style="font-size:1.25rem;font-weight:600;margin-top:1rem;">File d'appels vide 🎉</h2>
                <p class="cq-muted" style="margin-top:.5rem;">Aucun lead à appeler pour le moment. Les rappels planifiés réapparaîtront ici à leur échéance.</p>
                @if (count($skipped))
                    <x-filament::button color="gray" style="margin-top:1rem" wire:click="$set('skipped', [])">Revoir les {{ count($skipped) }} lead(s) passé(s)</x-filament::button>
                @endif
            </div>
        </x-filament::section>
    @else
        <div class="cq-grid" wire:key="lead-{{ $lead->id }}">
            <div style="display:flex;flex-direction:column;gap:1.5rem;">
                <x-filament::section>
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                        <div>
                            <div class="cq-muted">{{ $lead->reference }} · {{ $lead->type?->getLabel() }}</div>
                            <div class="cq-big">{{ $lead->company_name }}</div>
                            <div style="margin-top:.25rem;">{{ $lead->contact_name ?? 'Contact inconnu' }} @if($lead->contact_role)<span class="cq-muted">— {{ $lead->contact_role }}</span>@endif</div>
                            <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                                <x-filament::badge :color="$lead->status->getColor()">{{ $lead->status->getLabel() }}</x-filament::badge>
                                @if (! is_null($lead->score))
                                    <x-filament::badge :color="$lead->score >= 70 ? 'success' : ($lead->score >= 40 ? 'warning' : 'danger')">Score {{ $lead->score }}/100</x-filament::badge>
                                @endif
                                <x-filament::badge color="gray">{{ $lead->call_attempts }} tentative(s)</x-filament::badge>
                                @if ($lead->next_action)
                                    <x-filament::badge color="info">{{ $lead->next_action }}</x-filament::badge>
                                @endif
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:1.4rem;font-weight:600;letter-spacing:.02em;">{{ \App\Services\PhoneNormalizer::format($lead->dial_number) }}</div>
                            <div style="margin-top:.5rem;display:flex;gap:.5rem;justify-content:flex-end;">
                                {{ $this->appelerAction }}
                                {{ $this->ficheAction }}
                            </div>
                        </div>
                    </div>

                    <dl class="cq-kv" style="margin-top:1.25rem;">
                        <dt>Adresse</dt><dd>{{ trim($lead->address.' '.$lead->postal_code.' '.$lead->city) ?: '—' }}</dd>
                        <dt>Email</dt><dd>{{ $lead->email ?: '—' }}</dd>
                        <dt>Praticiens</dt><dd>{{ $lead->practitioners_count ?: '—' }}</dd>
                        <dt>Récupérateur actuel</dt><dd>{{ $lead->current_buyer ?: 'Inconnu' }}</dd>
                        <dt>Dernier contact</dt><dd>{{ $lead->last_contacted_at?->diffForHumans() ?? 'Jamais' }}</dd>
                        @if ($lead->notes)<dt>Notes</dt><dd style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($lead->notes, 400) }}</dd>@endif
                    </dl>
                </x-filament::section>

                <x-filament::section heading="Qualifier l'appel" icon="heroicon-o-clipboard-document-check">
                    <textarea wire:model="notes" rows="4" class="cq-textarea" placeholder="Notes : interlocuteur, volume de déchets, concurrent, objections… (analysées par l'IA)"></textarea>

                    <div style="display:flex;align-items:center;gap:.75rem;margin:.75rem 0 1rem;flex-wrap:wrap;">
                        <label class="cq-muted" for="followUpAt">Rappel le</label>
                        <input id="followUpAt" type="datetime-local" wire:model="followUpAt" class="cq-input" min="{{ now()->format('Y-m-d\TH:i') }}">
                        <span class="cq-muted">(obligatoire pour « Rappel demandé »)</span>
                    </div>

                    <div class="cq-outcomes">
                        @foreach (\App\Enums\CallOutcome::cases() as $o)
                            <x-filament::button :color="$o->getColor()" size="sm" wire:click="outcome('{{ $o->value }}')" wire:loading.attr="disabled">
                                {{ $o->getLabel() }}
                            </x-filament::button>
                        @endforeach
                    </div>

                    <div style="display:flex;justify-content:space-between;margin-top:1.25rem;gap:.5rem;flex-wrap:wrap;">
                        <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                            {{ $this->iaEmailAction }}
                            {{ $this->iaAskAction }}
                            {{ $this->noteAction }}
                        </div>
                        <x-filament::button color="gray" icon="heroicon-m-forward" wire:click="skip">Passer ce lead</x-filament::button>
                    </div>
                </x-filament::section>
            </div>

            <div style="display:flex;flex-direction:column;gap:1.5rem;">
                <x-filament::section heading="Aide IA" icon="heroicon-o-sparkles">
                    @if ($lead->ai_pitch)
                        <div class="cq-muted" style="margin-bottom:.35rem;">Accroche conseillée</div>
                        <div class="cq-pitch">{{ $lead->ai_pitch }}</div>

                        @if (! empty($lead->ai_insights['objections']))
                            <div class="cq-muted" style="margin:1rem 0 .35rem;">Objections probables</div>
                            <ul style="list-style:disc;padding-left:1.1rem;font-size:.85rem;display:flex;flex-direction:column;gap:.35rem;">
                                @foreach ($lead->ai_insights['objections'] as $obj)
                                    <li>{{ $obj }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if (! empty($lead->ai_insights['best_time_to_call']))
                            <div class="cq-muted" style="margin-top:1rem;">Meilleur créneau : <strong>{{ $lead->ai_insights['best_time_to_call'] }}</strong></div>
                        @endif
                    @else
                        <p class="cq-muted" style="margin-bottom:.75rem;">Ce lead n'a pas encore été analysé.</p>
                        {{ $this->iaScoreAction }}
                    @endif
                </x-filament::section>

                <x-filament::section heading="Historique" icon="heroicon-o-clock" collapsible>
                    @if ($lead->activities->isEmpty())
                        <p class="cq-muted">Aucun historique.</p>
                    @else
                        <ul class="cq-timeline">
                            @foreach ($lead->activities as $a)
                                <li>
                                    <div style="display:flex;justify-content:space-between;gap:.5rem;">
                                        <strong>{{ $a->subject }}</strong>
                                        <span class="cq-muted">{{ $a->occurred_at->format('d/m H:i') }}</span>
                                    </div>
                                    @if ($a->body)<div class="cq-muted" style="white-space:pre-line">{{ \Illuminate\Support\Str::limit($a->body, 220) }}</div>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>
            </div>
        </div>
    @endif

</x-filament-panels::page>
