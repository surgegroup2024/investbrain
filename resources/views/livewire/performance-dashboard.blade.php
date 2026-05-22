<?php

use App\Models\Holding;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component
{
    public string $sortBy = 'gain_dollars';

    public string $sortDir = 'desc';

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function getHoldingsProperty(): Collection
    {
        $portfolioIds = auth()->user()->portfolios->pluck('id');

        $holdings = Holding::whereIn('portfolio_id', $portfolioIds)
            ->where('quantity', '>', 0)
            ->with(['market_data', 'portfolio'])
            ->get();

        // Group by symbol across all accounts
        $bySymbol = $holdings->groupBy('symbol')->map(function ($group, $symbol) {
            $totalQuantity = $group->sum('quantity');
            $totalCostBasis = $group->sum(fn ($h) => ($h->average_cost_basis ?? 0) * $h->quantity);
            $marketPrice = $group->first()->market_data?->market_value ?? 0;
            $totalMarketValue = $marketPrice * $totalQuantity;
            $gainDollars = $totalMarketValue - $totalCostBasis;
            $gainPercent = $totalCostBasis > 0 ? (($totalMarketValue - $totalCostBasis) / $totalCostBasis) * 100 : 0;

            return [
                'symbol' => $symbol,
                'name' => $group->first()->market_data?->name ?? $symbol,
                'quantity' => $totalQuantity,
                'avg_cost' => $totalQuantity > 0 ? $totalCostBasis / $totalQuantity : 0,
                'market_price' => $marketPrice,
                'market_value' => $totalMarketValue,
                'cost_basis' => $totalCostBasis,
                'gain_dollars' => $gainDollars,
                'gain_percent' => $gainPercent,
                'accounts' => $group->count(),
            ];
        })->values();

        // Sort
        $sorted = match ($this->sortDir) {
            'desc' => $bySymbol->sortByDesc($this->sortBy),
            default => $bySymbol->sortBy($this->sortBy),
        };

        return $sorted->values();
    }

    public function getTopPerformersProperty(): Collection
    {
        return $this->holdings->sortByDesc('gain_percent')->take(5)->values();
    }

    public function getWorstPerformersProperty(): Collection
    {
        return $this->holdings->sortBy('gain_percent')->take(5)->values();
    }

    public function getBrokerMetricsProperty(): array
    {
        $portfolios = auth()->user()->portfolios->where('wishlist', false);
        $brokerTotal = $portfolios->sum('broker_value');
        $computedTotal = $this->holdings->sum('market_value');
        $computedCost = $this->holdings->sum('cost_basis');

        // Use broker value if it differs > 10% from computed (same logic as Dashboard)
        if ($brokerTotal > 0 && abs($brokerTotal - $computedTotal) > $brokerTotal * 0.1) {
            return [
                'market_value' => $brokerTotal,
                'cost_basis' => $computedCost,
                'gain' => $brokerTotal - $computedCost,
            ];
        }

        return [
            'market_value' => $computedTotal,
            'cost_basis' => $computedCost,
            'gain' => $computedTotal - $computedCost,
        ];
    }

    public function getTotalGainProperty(): float
    {
        return $this->brokerMetrics['gain'];
    }

    public function getTotalMarketValueProperty(): float
    {
        return $this->brokerMetrics['market_value'];
    }

    public function getTotalCostBasisProperty(): float
    {
        return $this->brokerMetrics['cost_basis'];
    }
}; ?>

<div>
    <style>
        .gain-pos { color: #4ade80 !important; }
        .gain-neg { color: #f87171 !important; }
        .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; }
        @media (min-width: 48rem) { .stat-grid { grid-template-columns: repeat(4, 1fr); } }
    </style>
    {{-- Summary stats (horizontal like dashboard) --}}
    <div class="mb-6">
        <x-ui.card dense>
            <div class="stat-grid divide-y md:divide-y-0 md:divide-x divide-base-300">
                <div class="p-4" title="{{ __('Current total value of all holdings at today\'s prices') }}">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Total Market Value') }}</div>
                    <div class="mt-1 text-xl font-black">{{ Number::currency($this->totalMarketValue, 'USD') }}</div>
                </div>
                <div class="p-4" title="{{ __('Total amount originally invested (purchase price × quantity)') }}">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Total Cost Basis') }}</div>
                    <div class="mt-1 text-xl font-black">{{ Number::currency($this->totalCostBasis, 'USD') }}</div>
                </div>
                <div class="p-4" title="{{ __('Unrealized profit/loss: Market Value minus Cost Basis') }}">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Total Gain/Loss') }}</div>
                    <div class="mt-1 text-xl font-black {{ $this->totalGain >= 0 ? 'gain-pos' : 'gain-neg' }}">{{ Number::currency($this->totalGain, 'USD') }}</div>
                </div>
                <div class="p-4" title="{{ __('Number of unique stock/ETF positions held') }}">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Positions') }}</div>
                    <div class="mt-1 text-xl font-black">{{ count($this->holdings) }}</div>
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- Best/Worst performers --}}
    <div class="grid md:grid-cols-2 gap-4 mb-6">
        <x-ui.card title="{{ __('Top Performers') }}">
            @foreach($this->topPerformers as $h)
                <div class="flex items-center justify-between py-2 {{ ! $loop->last ? 'border-b border-base-200' : '' }}">
                    <div>
                        <span class="font-semibold">{{ $h['symbol'] }}</span>
                        <span class="text-sm text-base-content/60 ml-2">{{ Str::limit($h['name'], 20) }}</span>
                    </div>
                    <span class="font-medium" style="color: #4ade80">+{{ number_format($h['gain_percent'], 1) }}%</span>
                </div>
            @endforeach
        </x-ui.card>
        <x-ui.card title="{{ __('Worst Performers') }}">
            @foreach($this->worstPerformers as $h)
                <div class="flex items-center justify-between py-2 {{ ! $loop->last ? 'border-b border-base-200' : '' }}">
                    <div>
                        <span class="font-semibold">{{ $h['symbol'] }}</span>
                        <span class="text-sm text-base-content/60 ml-2">{{ Str::limit($h['name'], 20) }}</span>
                    </div>
                    <span class="font-medium" style="color: {{ $h['gain_percent'] >= 0 ? '#4ade80' : '#f87171' }}">{{ number_format($h['gain_percent'], 1) }}%</span>
                </div>
            @endforeach
        </x-ui.card>
    </div>

    {{-- Full table --}}
    <x-ui.card title="{{ __('All Positions') }}">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sort('symbol')">
                            {{ __('Symbol') }}
                            @if($sortBy === 'symbol') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th>{{ __('Name') }}</th>
                        <th class="text-right cursor-pointer" wire:click="sort('quantity')">
                            {{ __('Qty') }}
                            @if($sortBy === 'quantity') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('market_value')">
                            {{ __('Market Value') }}
                            @if($sortBy === 'market_value') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('gain_dollars')">
                            {{ __('Gain $') }}
                            @if($sortBy === 'gain_dollars') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('gain_percent')">
                            {{ __('Gain %') }}
                            @if($sortBy === 'gain_percent') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right">{{ __('Accts') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->holdings as $h)
                        <tr class="hover">
                            <td class="font-semibold">{{ $h['symbol'] }}</td>
                            <td class="text-sm text-base-content/70 max-w-[150px] truncate">{{ $h['name'] }}</td>
                            <td class="text-right">{{ number_format($h['quantity'], 2) }}</td>
                            <td class="text-right">{{ Number::currency($h['market_value'], 'USD') }}</td>
                            <td class="text-right font-medium" style="color: {{ $h['gain_dollars'] >= 0 ? '#4ade80' : '#f87171' }}">
                                {{ $h['gain_dollars'] >= 0 ? '+' : '' }}{{ Number::currency($h['gain_dollars'], 'USD') }}
                            </td>
                            <td class="text-right font-medium" style="color: {{ $h['gain_percent'] >= 0 ? '#4ade80' : '#f87171' }}">
                                {{ $h['gain_percent'] >= 0 ? '+' : '' }}{{ number_format($h['gain_percent'], 1) }}%
                            </td>
                            <td class="text-right text-base-content/60">{{ $h['accounts'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
