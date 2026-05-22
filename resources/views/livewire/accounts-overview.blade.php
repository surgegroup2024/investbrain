<?php

use App\Models\Holding;
use App\Models\Portfolio;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component
{
    public function getPortfoliosProperty(): Collection
    {
        return auth()->user()->portfolios->load('holdings.market_data')->map(function ($portfolio) {
            $calculatedValue = $portfolio->holdings->sum(function ($holding) {
                return ($holding->market_data?->market_value ?? 0) * $holding->quantity;
            });

            // Use broker-reported value when available and significantly different
            $brokerValue = $portfolio->broker_value;
            $marketValue = ($brokerValue && abs($brokerValue - $calculatedValue) > $brokerValue * 0.1)
                ? $brokerValue
                : $calculatedValue;

            return [
                'id' => $portfolio->id,
                'title' => $portfolio->title,
                'market_value' => $marketValue,
                'broker_value' => $brokerValue,
                'calculated_value' => $calculatedValue,
                'has_discrepancy' => $brokerValue && abs($brokerValue - $calculatedValue) > max($brokerValue, 1) * 0.1,
            ];
        })->sortByDesc('market_value')->values();
    }

    public function getTotalValueProperty(): float
    {
        return $this->portfolios->sum('market_value');
    }
}; ?>

<div>
    {{-- Summary cards --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Total Portfolio Value') }}</div>
            <div class="text-2xl font-bold mt-1">{{ Number::currency($this->totalValue, 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Accounts') }}</div>
            <div class="text-2xl font-bold mt-1">{{ count($this->portfolios) }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Largest Account') }}</div>
            <div class="text-2xl font-bold mt-1">
                @if($this->portfolios->isNotEmpty())
                    {{ $this->portfolios->first()['title'] }}
                @else
                    —
                @endif
            </div>
        </x-ui.card>
    </div>

    {{-- Account list --}}
    <x-ui.card>
        @forelse($this->portfolios as $portfolio)
            <a
                href="{{ route('portfolio.show', $portfolio['id']) }}"
                wire:navigate
                class="flex items-center gap-4 px-4 py-3 hover:bg-base-200/50 transition-colors {{ ! $loop->last ? 'border-b border-base-200' : '' }}"
            >
                <div class="flex-1 min-w-0">
                    <div class="font-medium text-base-content">{{ $portfolio['title'] }}</div>
                    <div class="text-sm text-base-content/60">
                        {{ Number::currency($portfolio['market_value'], 'USD') }}
                        @if($portfolio['has_discrepancy'])
                            <span class="text-xs text-warning ml-1" title="{{ __('Broker reported value; calculated was') }} {{ Number::currency($portfolio['calculated_value'], 'USD') }}">⚠</span>
                        @endif
                    </div>
                </div>

                {{-- Allocation bar --}}
                <div class="w-32 hidden sm:block">
                    @php
                        $pct = $this->totalValue > 0 ? ($portfolio['market_value'] / $this->totalValue) * 100 : 0;
                    @endphp
                    <div class="w-full bg-base-200 rounded-full h-2">
                        <div class="bg-primary rounded-full h-2" style="width: {{ number_format($pct, 1) }}%"></div>
                    </div>
                </div>

                <div class="text-sm font-medium text-base-content/70 w-12 text-right">
                    {{ number_format($this->totalValue > 0 ? ($portfolio['market_value'] / $this->totalValue) * 100 : 0, 0) }}%
                </div>

                <x-ui.icon name="o-chevron-right" class="w-4 h-4 text-base-content/40" />
            </a>
        @empty
            <div class="text-center py-12 text-base-content/50">
                <p>{{ __('No accounts found') }}</p>
            </div>
        @endforelse
    </x-ui.card>
</div>
