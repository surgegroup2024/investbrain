<?php

use App\Models\Holding;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component
{
    public string $sortBy = 'gain_dollars';

    public string $sortDir = 'desc';

    public string $positionsView = 'table';

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function setPositionsView(string $view): void
    {
        if (in_array($view, ['table', 'chart'], true)) {
            $this->positionsView = $view;
        }
    }

    public function getAllocationChartDataProperty(): array
    {
        $holdings = $this->holdings;

        $empty = [
            'nodes' => [],
            'totals' => ['cost_basis' => 0, 'market_value' => 0, 'gain_dollars' => 0, 'count' => 0],
        ];

        if ($holdings->isEmpty()) {
            return $empty;
        }

        $sorted = $holdings->sortByDesc('market_value')->values();
        $totalMv = (float) $sorted->sum('market_value');
        $totalCb = (float) $sorted->sum('cost_basis');

        $nodes = [];
        foreach ($sorted as $h) {
            $cb = (float) $h['cost_basis'];
            $mv = (float) $h['market_value'];
            $gd = (float) $h['gain_dollars'];

            $nodes[] = [
                'x' => $h['symbol'],
                'y' => round($mv, 2),
                'symbol' => $h['symbol'],
                'name' => $h['name'] ?? $h['symbol'],
                'cost_basis' => round($cb, 2),
                'market_value' => round($mv, 2),
                'gain_dollars' => round($gd, 2),
                'gain_percent' => round((float) $h['gain_percent'], 1),
                'portfolio_pct' => $totalMv > 0 ? round(($mv / $totalMv) * 100, 1) : 0,
            ];
        }

        return [
            'nodes' => $nodes,
            'totals' => [
                'cost_basis' => round($totalCb, 2),
                'market_value' => round($totalMv, 2),
                'gain_dollars' => round($totalMv - $totalCb, 2),
                'count' => $sorted->count(),
            ],
        ];
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

        $portfolioMarketValue = (float) $bySymbol->sum('market_value');
        $bySymbol = $bySymbol->map(function ($holding) use ($portfolioMarketValue) {
            $holding['portfolio_pct'] = $portfolioMarketValue > 0
                ? (($holding['market_value'] / $portfolioMarketValue) * 100)
                : 0;

            return $holding;
        });

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

    {{-- Full table / position allocation chart --}}
    <x-ui.card>
        <div class="flex items-center justify-between mb-3 gap-3 flex-wrap">
            <h3 class="text-xl font-bold leading-none tracking-tight">{{ __('All Positions') }}</h3>
            <div class="join">
                <button type="button"
                        wire:click="setPositionsView('table')"
                        class="join-item btn btn-sm {{ $positionsView === 'table' ? 'btn-primary' : 'btn-ghost' }}">
                    {{ __('Table') }}
                </button>
                <button type="button"
                        wire:click="setPositionsView('chart')"
                        class="join-item btn btn-sm {{ $positionsView === 'chart' ? 'btn-primary' : 'btn-ghost' }}">
                    {{ __('Chart') }}
                </button>
            </div>
        </div>

        @if($positionsView === 'table')
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th class="cursor-pointer" wire:click="sort('symbol')">
                            {{ __('Symbol') }}
                            @if($sortBy === 'symbol') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('quantity')">
                            {{ __('Qty') }}
                            @if($sortBy === 'quantity') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('avg_cost')">
                            {{ __('Avg Cost') }}
                            @if($sortBy === 'avg_cost') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('market_price')">
                            {{ __('Price') }}
                            @if($sortBy === 'market_price') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('cost_basis')">
                            {{ __('Cost Basis') }}
                            @if($sortBy === 'cost_basis') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('market_value')">
                            {{ __('Market Value') }}
                            @if($sortBy === 'market_value') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
                        </th>
                        <th class="text-right cursor-pointer" wire:click="sort('portfolio_pct')">
                            {{ __('Portfolio %') }}
                            @if($sortBy === 'portfolio_pct') <span>{{ $sortDir === 'asc' ? '▲' : '▼' }}</span> @endif
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
                            <td class="text-right">{{ number_format($h['quantity'], 2) }}</td>
                            <td class="text-right">{{ Number::currency($h['avg_cost'], 'USD') }}</td>
                            <td class="text-right">{{ Number::currency($h['market_price'], 'USD') }}</td>
                            <td class="text-right">{{ Number::currency($h['cost_basis'], 'USD') }}</td>
                            <td class="text-right">{{ Number::currency($h['market_value'], 'USD') }}</td>
                            <td class="text-right">{{ number_format($h['portfolio_pct'], 1) }}%</td>
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
        @else
            @php $chart = $this->allocationChartData; @endphp
            @if(empty($chart['nodes']))
                <div class="py-10 text-center text-sm text-base-content/50">
                    {{ __('No positions to chart.') }}
                </div>
            @else
                @php $chartId = 'allocation-chart-'.md5(json_encode(array_column($chart['nodes'], 'x'))); @endphp
                <div wire:ignore wire:key="{{ $chartId }}">
                    <div id="{{ $chartId }}"
                         data-chart="{{ json_encode($chart) }}"
                         x-init="window.renderAllocationChart && window.renderAllocationChart($el)"
                         style="min-height: 460px;"></div>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs">
                    <p class="text-base-content/50 max-w-xl">
                        {{ __('Each tile is a holding. Size = share of portfolio (market value), color = current gain/loss %. Brighter green = bigger gain, brighter red = bigger loss. Hover a tile for full detail.') }}
                    </p>
                    <div class="flex items-center gap-3 font-medium whitespace-nowrap">
                        <span>{{ __('Cost Basis') }} {{ Number::currency($chart['totals']['cost_basis'], 'USD') }}</span>
                        <span>{{ __('Market Value') }} {{ Number::currency($chart['totals']['market_value'], 'USD') }}</span>
                        <span style="color: {{ $chart['totals']['gain_dollars'] >= 0 ? '#4ade80' : '#f87171' }}">
                            {{ __('Net') }} {{ $chart['totals']['gain_dollars'] >= 0 ? '+' : '' }}{{ Number::currency($chart['totals']['gain_dollars'], 'USD') }}
                        </span>
                        <span class="text-base-content/60">{{ $chart['totals']['count'] }} {{ __('positions') }}</span>
                    </div>
                </div>
            @endif
        @endif
    </x-ui.card>

    {{-- Per-position heatmap (treemap) renderer (defined once at initial page load; called via x-init when the chart container is morphed into the DOM) --}}
    <script>
        if (!window.renderAllocationChart) {
            window.__allocationCharts = window.__allocationCharts || {};

            window.__heatColor = function (pct) {
                const cap = 25;
                const t = Math.max(-cap, Math.min(cap, Number(pct) || 0)) / cap; // -1..1
                const flat = [71, 85, 105];    // slate-600
                const green = [22, 163, 74];    // green-600
                const red = [220, 38, 38];      // red-600
                const mix = function (a, b, k) {
                    return 'rgb(' + Math.round(a[0] + (b[0] - a[0]) * k)
                        + ',' + Math.round(a[1] + (b[1] - a[1]) * k)
                        + ',' + Math.round(a[2] + (b[2] - a[2]) * k) + ')';
                };
                // Floor the intensity so even tiny moves are visibly tinted
                const k = 0.25 + 0.75 * Math.abs(t);
                return t >= 0 ? mix(flat, green, k) : mix(flat, red, k);
            };

            window.renderAllocationChart = function (el) {
                if (!el || typeof window.ApexCharts === 'undefined') return;
                if (window.__allocationCharts[el.id]) {
                    try { window.__allocationCharts[el.id].destroy(); } catch (e) {}
                    delete window.__allocationCharts[el.id];
                }
                let chartData;
                try { chartData = JSON.parse(el.dataset.chart); }
                catch (e) { console.error('allocation chart: bad data', e); return; }

                const nodes = chartData.nodes || [];
                const colors = nodes.map(function (n) { return window.__heatColor(n.gain_percent); });

                const fmtMoneyFull = function (v) {
                    return (Number(v) || 0).toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });
                };

                const options = {
                    chart: { type: 'treemap', height: 460, toolbar: { show: false }, foreColor: '#e5e7eb', animations: { enabled: false } },
                    legend: { show: false },
                    colors: colors,
                    plotOptions: {
                        treemap: {
                            distributed: true,
                            enableShades: false,
                        }
                    },
                    stroke: { width: 2, colors: ['#0b0f17'] },
                    dataLabels: {
                        enabled: true,
                        style: { fontSize: '13px', fontWeight: 700, colors: ['#ffffff'] },
                        offsetY: -4,
                        formatter: function (text, op) {
                            const n = nodes[op.dataPointIndex] || {};
                            const sign = (n.gain_percent || 0) >= 0 ? '+' : '';
                            return [text, sign + (n.gain_percent || 0) + '%'];
                        }
                    },
                    series: [{ data: nodes.map(function (n) { return { x: n.x, y: n.y }; }) }],
                    tooltip: {
                        custom: function (opts) {
                            const n = nodes[opts.dataPointIndex] || {};
                            const gainColor = (n.gain_dollars || 0) >= 0 ? '#4ade80' : '#f87171';
                            const sign = (n.gain_dollars || 0) >= 0 ? '+' : '';
                            return ''
                                + '<div style="padding:8px 10px;font-size:12px;line-height:1.45">'
                                +   '<div style="font-weight:700;margin-bottom:4px">' + (n.symbol || '') + ' <span style="color:#999;font-weight:400">' + (n.name || '') + '</span></div>'
                                +   '<div>Market Value: <b>' + fmtMoneyFull(n.market_value) + '</b> <span style="color:#999">(' + (n.portfolio_pct || 0) + '% of portfolio)</span></div>'
                                +   '<div>Cost Basis: <b>' + fmtMoneyFull(n.cost_basis) + '</b></div>'
                                +   '<div style="color:' + gainColor + '">Gain/Loss: <b>' + sign + fmtMoneyFull(n.gain_dollars) + ' (' + sign + (n.gain_percent || 0) + '%)</b></div>'
                                + '</div>';
                        }
                    }
                };

                const c = new window.ApexCharts(el, options);
                c.render();
                window.__allocationCharts[el.id] = c;
            };
        }
    </script>
</div>
