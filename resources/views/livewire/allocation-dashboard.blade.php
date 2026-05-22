<?php

use App\Models\Holding;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component
{
    public function getPortfolioIdsProperty(): array
    {
        return auth()->user()->portfolios->pluck('id')->toArray();
    }

    public function getHoldingsBySymbolProperty(): Collection
    {
        $holdings = Holding::whereIn('portfolio_id', $this->portfolioIds)
            ->with(['market_data', 'portfolio'])
            ->get();

        return $holdings->groupBy('symbol')->map(function ($group, $symbol) {
            $totalQuantity = $group->sum('quantity');
            $marketPrice = $group->first()->market_data?->market_value ?? 0;
            $marketValue = $marketPrice * $totalQuantity;

            return [
                'symbol' => $symbol,
                'name' => $group->first()->market_data?->name ?? $symbol,
                'market_value' => $marketValue,
                'quantity' => $totalQuantity,
                'accounts' => $group->pluck('portfolio.title')->unique()->values()->toArray(),
            ];
        })->sortByDesc('market_value')->values();
    }

    public function getTotalValueProperty(): float
    {
        return $this->holdingsBySymbol->sum('market_value');
    }

    public function getTopPositionsProperty(): Collection
    {
        return $this->holdingsBySymbol->take(10);
    }

    public function getConcentrationWarningsProperty(): Collection
    {
        if ($this->totalValue <= 0) {
            return collect();
        }

        return $this->holdingsBySymbol->filter(function ($h) {
            return ($h['market_value'] / $this->totalValue) * 100 >= 10;
        })->values();
    }

    public function getByAccountTypeProperty(): array
    {
        $portfolios = auth()->user()->portfolios->load('holdings.market_data');
        $taxAdvantaged = 0;
        $taxable = 0;

        foreach ($portfolios as $portfolio) {
            $value = $portfolio->holdings->sum(fn ($h) => ($h->market_data?->market_value ?? 0) * $h->quantity);
            $type = strtoupper($portfolio->account_type ?? '');

            if (in_array($type, ['IRA', 'ROTH_IRA', 'SEP_IRA', '401K', '403B', 'HSA'])) {
                $taxAdvantaged += $value;
            } else {
                $taxable += $value;
            }
        }

        $total = $taxAdvantaged + $taxable;

        return [
            'tax_advantaged' => $taxAdvantaged,
            'taxable' => $taxable,
            'tax_advantaged_pct' => $total > 0 ? ($taxAdvantaged / $total) * 100 : 0,
            'taxable_pct' => $total > 0 ? ($taxable / $total) * 100 : 0,
        ];
    }
}; ?>

<div>
    {{-- Summary cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Total Value') }}</div>
            <div class="text-xl font-bold mt-1">{{ Number::currency($this->totalValue, 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Unique Positions') }}</div>
            <div class="text-xl font-bold mt-1">{{ count($this->holdingsBySymbol) }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Largest Position') }}</div>
            <div class="text-xl font-bold mt-1">
                @if($this->holdingsBySymbol->isNotEmpty())
                    {{ $this->holdingsBySymbol->first()['symbol'] }}
                    ({{ number_format($this->totalValue > 0 ? ($this->holdingsBySymbol->first()['market_value'] / $this->totalValue) * 100 : 0, 1) }}%)
                @else
                    —
                @endif
            </div>
        </x-ui.card>
    </div>

    {{-- Account type split --}}
    <x-ui.card title="{{ __('Tax-Advantaged vs Taxable') }}" class="mb-6">
        <div class="flex items-center gap-4 mb-3">
            <div class="flex-1">
                <div class="flex justify-between text-sm mb-1">
                    <span>{{ __('Tax-Advantaged (IRA/401K)') }}</span>
                    <span class="font-medium">{{ Number::currency($this->byAccountType['tax_advantaged'], 'USD') }} ({{ number_format($this->byAccountType['tax_advantaged_pct'], 0) }}%)</span>
                </div>
                <div class="w-full bg-base-200 rounded-full h-3">
                    <div class="bg-info rounded-full h-3" style="width: {{ $this->byAccountType['tax_advantaged_pct'] }}%"></div>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <div class="flex-1">
                <div class="flex justify-between text-sm mb-1">
                    <span>{{ __('Taxable (Individual/Joint)') }}</span>
                    <span class="font-medium">{{ Number::currency($this->byAccountType['taxable'], 'USD') }} ({{ number_format($this->byAccountType['taxable_pct'], 0) }}%)</span>
                </div>
                <div class="w-full bg-base-200 rounded-full h-3">
                    <div class="bg-warning rounded-full h-3" style="width: {{ $this->byAccountType['taxable_pct'] }}%"></div>
                </div>
            </div>
        </div>
    </x-ui.card>

    {{-- Concentration warnings --}}
    @if($this->concentrationWarnings->isNotEmpty())
        <div class="alert alert-warning mb-6">
            <x-ui.icon name="o-exclamation-triangle" class="w-5 h-5" />
            <div>
                <div class="font-medium">{{ __('Concentration Warning') }}</div>
                <div class="text-sm">
                    {{ __('The following positions are > 10% of your portfolio:') }}
                    @foreach($this->concentrationWarnings as $w)
                        <span class="font-semibold">{{ $w['symbol'] }}</span> ({{ number_format(($w['market_value'] / $this->totalValue) * 100, 1) }}%){{ ! $loop->last ? ',' : '' }}
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Top positions table --}}
    <x-ui.card title="{{ __('Top 10 Positions') }}" class="mb-6">
        @foreach($this->topPositions as $position)
            <div class="flex items-center gap-3 py-3 {{ ! $loop->last ? 'border-b border-base-200' : '' }}">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-semibold">{{ $position['symbol'] }}</span>
                        <span class="text-sm text-base-content/60 truncate">{{ $position['name'] }}</span>
                    </div>
                    <div class="text-xs text-base-content/50 mt-0.5">
                        {{ implode(', ', $position['accounts']) }}
                    </div>
                </div>

                <div class="w-32 hidden sm:block">
                    @php $pct = $this->totalValue > 0 ? ($position['market_value'] / $this->totalValue) * 100 : 0; @endphp
                    <div class="w-full bg-base-200 rounded-full h-2">
                        <div class="bg-primary rounded-full h-2" style="width: {{ min($pct, 100) }}%"></div>
                    </div>
                </div>

                <div class="text-right shrink-0">
                    <div class="font-medium">{{ Number::currency($position['market_value'], 'USD') }}</div>
                    <div class="text-xs text-base-content/60">{{ number_format($this->totalValue > 0 ? ($position['market_value'] / $this->totalValue) * 100 : 0, 1) }}%</div>
                </div>
            </div>
        @endforeach
    </x-ui.card>

    {{-- Full allocation list --}}
    <x-ui.card title="{{ __('All Positions') }}">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('Symbol') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th class="text-right">{{ __('Value') }}</th>
                        <th class="text-right">{{ __('% of Portfolio') }}</th>
                        <th class="text-right">{{ __('Accounts') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->holdingsBySymbol as $h)
                        <tr class="hover">
                            <td class="font-semibold">{{ $h['symbol'] }}</td>
                            <td class="text-sm text-base-content/70 max-w-[150px] truncate">{{ $h['name'] }}</td>
                            <td class="text-right">{{ Number::currency($h['market_value'], 'USD') }}</td>
                            <td class="text-right">{{ number_format($this->totalValue > 0 ? ($h['market_value'] / $this->totalValue) * 100 : 0, 1) }}%</td>
                            <td class="text-right text-base-content/60">{{ count($h['accounts']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
