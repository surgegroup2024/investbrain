<?php

use App\Models\OptionActivity;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    public string $year = '';

    public function mount(): void
    {
        $this->year = (string) now()->year;
    }

    public function getPortfolioIdsProperty(): array
    {
        return auth()->user()->portfolios->pluck('id')->toArray();
    }

    public function getSalesProperty(): Collection
    {
        $query = Transaction::whereIn('portfolio_id', $this->portfolioIds)
            ->where('transaction_type', 'SELL')
            ->with(['portfolio', 'market_data']);

        if ($this->year) {
            $query->whereYear('date', $this->year);
        }

        $sales = $query->orderByDesc('date')->get();

        // Calculate holding period (FIFO) for each sale
        return $sales->map(function ($tx) {
            $firstBuy = Transaction::where('portfolio_id', $tx->portfolio_id)
                ->where('symbol', $tx->symbol)
                ->where('transaction_type', 'BUY')
                ->where('date', '<=', $tx->date)
                ->orderBy('date')
                ->first();

            $holdingDays = $firstBuy ? $firstBuy->date->diffInDays($tx->date) : 0;
            $tx->term = $holdingDays > 365 ? 'Long-term' : 'Short-term';
            $tx->type = 'Stock';
            $tx->gain = (($tx->sale_price ?? 0) - ($tx->cost_basis ?? 0)) * $tx->quantity;

            return $tx;
        });
    }

    public function getOptionsRealizedProperty(): Collection
    {
        // Net options P&L by symbol (premiums received - paid = realized gain/loss)
        $query = OptionActivity::whereIn('portfolio_id', $this->portfolioIds);

        if ($this->year) {
            $query->whereYear('date', $this->year);
        }

        return $query->get()->groupBy('symbol')->map(function ($activities, $symbol) {
            $received = abs($activities->whereIn('action', ['SELL_TO_OPEN', 'SELL_TO_CLOSE'])->sum('total_premium'));
            $paid = abs($activities->whereIn('action', ['BUY_TO_OPEN', 'BUY_TO_CLOSE'])->sum('total_premium'));
            $net = $received - $paid;

            return [
                'symbol' => $symbol,
                'type' => 'Options',
                'term' => 'Short-term',
                'sales' => $activities->count(),
                'proceeds' => $received,
                'cost_basis' => $paid,
                'gain' => $net,
            ];
        })->filter(fn ($r) => $r['proceeds'] > 0 || $r['cost_basis'] > 0)->values();
    }

    public function getRealizedGainsProperty(): array
    {
        $stockGain = $this->sales->sum('gain');
        $optionsGain = $this->optionsRealized->sum('gain');
        $totalGain = $stockGain + $optionsGain;

        $stockGains = $this->sales->filter(fn ($tx) => $tx->gain > 0)->sum('gain');
        $optionsGains = $this->optionsRealized->filter(fn ($r) => $r['gain'] > 0)->sum('gain');

        $stockLosses = $this->sales->filter(fn ($tx) => $tx->gain < 0)->sum('gain');
        $optionsLosses = $this->optionsRealized->filter(fn ($r) => $r['gain'] < 0)->sum('gain');

        $longTerm = $this->sales->where('term', 'Long-term')->sum('gain');
        $shortTerm = $this->sales->where('term', 'Short-term')->sum('gain') + $optionsGain;

        return [
            'total' => $totalGain,
            'gains' => $stockGains + $optionsGains,
            'losses' => $stockLosses + $optionsLosses,
            'sales_count' => $this->sales->count(),
            'options_count' => $this->optionsRealized->sum('sales'),
            'long_term' => $longTerm,
            'short_term' => $shortTerm,
        ];
    }

    public function getBySymbolProperty(): Collection
    {
        // Stock sales grouped by symbol
        $stockSymbols = $this->sales->groupBy('symbol')->map(function ($txs, $symbol) {
            return [
                'symbol' => $symbol,
                'type' => 'Stock',
                'term' => $txs->where('term', 'Long-term')->count() > 0
                    ? ($txs->where('term', 'Short-term')->count() > 0 ? 'Mixed' : 'Long-term')
                    : 'Short-term',
                'sales' => $txs->count(),
                'proceeds' => $txs->sum(fn ($tx) => ($tx->sale_price ?? 0) * $tx->quantity),
                'cost_basis' => $txs->sum(fn ($tx) => ($tx->cost_basis ?? 0) * $tx->quantity),
                'gain' => $txs->sum('gain'),
            ];
        })->values();

        return $stockSymbols->merge($this->optionsRealized)->sortByDesc('gain')->values();
    }

    public function getAvailableYearsProperty(): array
    {
        $stockYears = Transaction::whereIn('portfolio_id', $this->portfolioIds)
            ->where('transaction_type', 'SELL')
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM date) as year')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y) => (string) intval($y));

        $optionYears = OptionActivity::whereIn('portfolio_id', $this->portfolioIds)
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM date) as year')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y) => (string) intval($y));

        return $stockYears->merge($optionYears)->unique()->sortDesc()->values()->toArray();
    }
}; ?>

<div>
    {{-- Year selector --}}
    <div class="flex items-center gap-3 mb-4">
        <select wire:model.live="year" class="select select-sm select-bordered">
            <option value="">{{ __('All Time') }}</option>
            @foreach($this->availableYears as $y)
                <option value="{{ $y }}">{{ $y }}</option>
            @endforeach
        </select>
    </div>

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Net Realized') }}</div>
            <div class="text-xl font-bold {{ $this->realizedGains['total'] >= 0 ? 'text-success' : 'text-error' }} mt-1">
                {{ Number::currency($this->realizedGains['total'], 'USD') }}
            </div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Long-term') }}</div>
            <div class="text-xl font-bold {{ $this->realizedGains['long_term'] >= 0 ? 'text-success' : 'text-error' }} mt-1">
                {{ Number::currency($this->realizedGains['long_term'], 'USD') }}
            </div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Short-term') }}</div>
            <div class="text-xl font-bold {{ $this->realizedGains['short_term'] >= 0 ? 'text-success' : 'text-error' }} mt-1">
                {{ Number::currency($this->realizedGains['short_term'], 'USD') }}
            </div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Total Gains') }}</div>
            <div class="text-xl font-bold text-success mt-1">{{ Number::currency($this->realizedGains['gains'], 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Total Losses') }}</div>
            <div class="text-xl font-bold text-error mt-1">{{ Number::currency($this->realizedGains['losses'], 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Stock / Options') }}</div>
            <div class="text-xl font-bold mt-1">{{ $this->realizedGains['sales_count'] }} / {{ $this->realizedGains['options_count'] }}</div>
        </x-ui.card>
    </div>

    {{-- By symbol table --}}
    <x-ui.card title="{{ __('Realized Gains by Symbol') }}" class="mb-6">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('Symbol') }}</th>
                        <th>{{ __('Type') }}</th>
                        <th>{{ __('Term') }}</th>
                        <th class="text-right">{{ __('Sales') }}</th>
                        <th class="text-right">{{ __('Proceeds') }}</th>
                        <th class="text-right">{{ __('Cost Basis') }}</th>
                        <th class="text-right">{{ __('Gain/Loss') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->bySymbol as $row)
                        <tr class="hover">
                            <td class="font-semibold">{{ $row['symbol'] }}</td>
                            <td>
                                <span class="badge badge-sm {{ $row['type'] === 'Options' ? 'badge-secondary' : 'badge-primary' }}">
                                    {{ $row['type'] }}
                                </span>
                            </td>
                            <td>
                                <span class="text-sm {{ $row['term'] === 'Long-term' ? 'text-success' : 'text-warning' }}">
                                    {{ $row['term'] }}
                                </span>
                            </td>
                            <td class="text-right">{{ $row['sales'] }}</td>
                            <td class="text-right">{{ Number::currency($row['proceeds'], 'USD') }}</td>
                            <td class="text-right">{{ Number::currency($row['cost_basis'], 'USD') }}</td>
                            <td class="text-right font-medium {{ $row['gain'] >= 0 ? 'text-success' : 'text-error' }}">
                                {{ $row['gain'] >= 0 ? '+' : '' }}{{ Number::currency($row['gain'], 'USD') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-base-content/50">{{ __('No sales recorded') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>

    {{-- Recent sales --}}
    <x-ui.card title="{{ __('Recent Sales') }}">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Symbol') }}</th>
                        <th>{{ __('Type') }}</th>
                        <th>{{ __('Term') }}</th>
                        <th>{{ __('Account') }}</th>
                        <th class="text-right">{{ __('Qty') }}</th>
                        <th class="text-right">{{ __('Sale Price') }}</th>
                        <th class="text-right">{{ __('Cost Basis') }}</th>
                        <th class="text-right">{{ __('Gain/Loss') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->sales->take(50) as $tx)
                        <tr class="hover">
                            <td>{{ $tx->date->format('M j, Y') }}</td>
                            <td class="font-semibold">{{ $tx->symbol }}</td>
                            <td>
                                <span class="badge badge-sm badge-primary">Stock</span>
                            </td>
                            <td>
                                <span class="text-sm {{ $tx->term === 'Long-term' ? 'text-success' : 'text-warning' }}">
                                    {{ $tx->term }}
                                </span>
                            </td>
                            <td class="text-sm text-base-content/70">{{ $tx->portfolio?->title }}</td>
                            <td class="text-right">{{ number_format($tx->quantity, 2) }}</td>
                            <td class="text-right">{{ Number::currency($tx->sale_price ?? 0, 'USD') }}</td>
                            <td class="text-right">{{ Number::currency($tx->cost_basis ?? 0, 'USD') }}</td>
                            <td class="text-right font-medium {{ $tx->gain >= 0 ? 'text-success' : 'text-error' }}">
                                {{ $tx->gain >= 0 ? '+' : '' }}{{ Number::currency($tx->gain, 'USD') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
