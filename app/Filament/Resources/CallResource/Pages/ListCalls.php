<?php

namespace App\Filament\Resources\CallResource\Pages;

use App\Enums\CallStatus;
use App\Filament\Resources\CallResource;
use App\Models\Call;
use App\Models\TelephonyProvider;
use App\Services\Telephony\CallSynchronizer;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListCalls extends ListRecords
{
    protected static string $resource = CallResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync')
                ->label('Récupérer les derniers appels')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => Auth::user()->canManageTeam() && TelephonyProvider::active()->exists())
                ->action(function () {
                    $total = 0;
                    $errors = [];
                    foreach (TelephonyProvider::active()->get() as $provider) {
                        if (! $provider->driver()->supportsSync()) {
                            continue;
                        }
                        try {
                            $total += app(CallSynchronizer::class)->sync($provider, now()->subDay());
                        } catch (\Throwable $e) {
                            $errors[] = $provider->name.' : '.$e->getMessage();
                        }
                    }

                    $n = Notification::make()->title("{$total} appel(s) récupéré(s)");
                    $errors ? $n->body(implode("\n", $errors))->warning()->persistent() : $n->success();
                    $n->send();
                }),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (callable $scope) => $scope(Call::query()->visibleTo())->where('started_at', '>=', today())->count() ?: null;
        $missed = fn (Builder $query) => $query->where('direction', 'in')->whereIn('status', [CallStatus::Missed->value, CallStatus::Voicemail->value]);
        $unreached = fn (Builder $query) => $query->where('direction', 'out')->whereIn('status', [CallStatus::NoAnswer->value, CallStatus::Busy->value, CallStatus::Failed->value, CallStatus::Cancelled->value]);

        return [
            'tous' => Tab::make('Tous'),
            'emis' => Tab::make('Émis')->icon('heroicon-o-phone-arrow-up-right')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', 'out')),
            'recus' => Tab::make('Reçus')->icon('heroicon-o-phone-arrow-down-left')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('direction', 'in')),
            'manques' => Tab::make('Manqués')->icon('heroicon-o-phone-x-mark')
                ->badge(fn () => $count($missed))->badgeColor('danger')
                ->modifyQueryUsing($missed),
            'injoignables' => Tab::make('Injoignables')
                ->badge(fn () => $count($unreached))->badgeColor('warning')
                ->modifyQueryUsing($unreached),
            'decroches' => Tab::make('Décrochés')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', CallStatus::Answered->value)),
            'sans_lead' => Tab::make('Sans lead')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('lead_id')),
        ];
    }
}
