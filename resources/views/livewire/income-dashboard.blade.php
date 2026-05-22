<?php

use App\Models\CashFlow;
use App\Models\Holding;
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

    public function getDividendsProperty(): float
    {
        $query = DB::table('dividends')
            ->join('transactions as tx', function ($join) {
                $join->on('tx.symbol', '=', 'dividends.symbol')
                     ->on('tx.date', '<=', 'dividends.date');
            })
            ->whereIn('tx.portfolio_id', $this->portfolioIds);

        if ($this->year) {
            $query->whereYear('dividends.date', $this->year);
        }

        $result = $query->selectRaw("
            SUM(
                (CASE WHEN tx.transaction_type = 'BUY' THEN tx.quantity ELSE 0 END
                - CASE WHEN tx.transaction_type = 'SELL' THEN tx.quantity ELSE 0 END)
                * dividends.dividend_amount
            ) as total
        ")->value('total');

        return max(0, (float) $result);
    }

    public function getOptionsIncomeProperty(): float
    {
        $query = OptionActivity::whereIn('portfolio_id', $this->portfolioIds);

        if ($this->year) {
            $query->whereYear('date', $this->year);
        }

        $received = abs((clone $query)->premiumReceived()->sum('total_premium'));
        $paid = abs((clone $query)->premiumPaid()->sum('total_premium'));

        return $received - $paid;
    }

    public function getInterestProperty(): float
    {
        // Interest would come from cash flows or a separate source
        // For now, return 0 - can be enhanced later
        return 0;
    }

    public function getTotalIncomeProperty(): float
    {
        return $this->dividends + $this->optionsIncome + $this->interest;
    }

    public function getMonthlyBreakdownProperty(): array
    {
        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $divs = (float) DB::table('dividends')
                ->join('transactions as tx', function ($join) {
                    $join->on('tx.symbol', '=', 'dividends.symbol')
                         ->on('tx.date', '<=', 'dividends.date');
                })
                ->whereIn('tx.portfolio_id', $this->portfolioIds)
                ->whereMonth('dividends.date', $m)
                ->when($this->year, fn ($q) => $q->whereYear('dividends.date', $this->year))
                ->selectRaw("
                    SUM(
                        (CASE WHEN tx.transaction_type = 'BUY' THEN tx.quantity ELSE 0 END
                        - CASE WHEN tx.transaction_type = 'SELL' THEN tx.quantity ELSE 0 END)
                        * dividends.dividend_amount
                    ) as total
                ")->value('total');

            $divs = max(0, $divs);

            $optQuery = OptionActivity::whereIn('portfolio_id', $this->portfolioIds)
                ->whereMonth('date', $m);
            if ($this->year) {
                $optQuery->whereYear('date', $this->year);
            }
            $optReceived = abs((clone $optQuery)->premiumReceived()->sum('total_premium'));
            $optPaid = abs((clone $optQuery)->premiumPaid()->sum('total_premium'));

            $months[] = [
                'month' => date('M', mktime(0, 0, 0, $m, 1)),
                'dividends' => $divs,
                'options' => $optReceived - $optPaid,
                'total' => $divs + ($optReceived - $optPaid),
            ];
        }

        return $months;
    }

    public function getTopIncomeSymbolsProperty(): Collection
    {
        // Options income by symbol
        $optQuery = OptionActivity::whereIn('portfolio_id', $this->portfolioIds);
        if ($this->year) {
            $optQuery->whereYear('date', $this->year);
        }

        $optionsSymbols = $optQuery->get()
            ->groupBy('symbol')
            ->map(function ($activities, $symbol) {
                $received = abs($activities->whereIn('action', ['SELL_TO_OPEN', 'SELL_TO_CLOSE'])->sum('total_premium'));
                $paid = abs($activities->whereIn('action', ['BUY_TO_OPEN', 'BUY_TO_CLOSE'])->sum('total_premium'));

                return [
                    'symbol' => $symbol,
                    'income' => $received - $paid,
                    'source' => 'Options',
                ];
            })
            ->filter(fn ($item) => $item['income'] > 0);

        // Dividend income by symbol
        $divQuery = DB::table('dividends')
            ->join('transactions as tx', function ($join) {
                $join->on('tx.symbol', '=', 'dividends.symbol')
                     ->on('tx.date', '<=', 'dividends.date');
            })
            ->whereIn('tx.portfolio_id', $this->portfolioIds);

        if ($this->year) {
            $divQuery->whereYear('dividends.date', $this->year);
        }

        $divSymbols = $divQuery
            ->groupBy('dividends.symbol')
            ->selectRaw("
                dividends.symbol,
                SUM(
                    (CASE WHEN tx.transaction_type = 'BUY' THEN tx.quantity ELSE 0 END
                    - CASE WHEN tx.transaction_type = 'SELL' THEN tx.quantity ELSE 0 END)
                    * dividends.dividend_amount
                ) as total
            ")
            ->get()
            ->filter(fn ($r) => $r->total > 0)
            ->map(fn ($r) => [
                'symbol' => $r->symbol,
                'income' => (float) $r->total,
                'source' => 'Dividends',
            ]);

        return $optionsSymbols->values()
            ->merge($divSymbols)
            ->sortByDesc('income')
            ->values()
            ->take(10);
    }

    public function getAvailableYearsProperty(): array
    {
        $optYears = OptionActivity::whereIn('portfolio_id', $this->portfolioIds)
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM date) as year')
            ->pluck('year')
            ->map(fn ($y) => (string) intval($y));

        $txYears = Transaction::whereIn('portfolio_id', $this->portfolioIds)
            ->where('reinvested_dividend', true)
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM date) as year')
            ->pluck('year')
            ->map(fn ($y) => (string) intval($y));

        return $optYears->merge($txYears)->unique()->sortDesc()->values()->toArray();
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
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Total Income') }}</div>
            <div class="text-xl font-bold text-success mt-1">{{ Number::currency($this->totalIncome, 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Dividends') }}</div>
            <div class="text-xl font-bold mt-1">{{ Number::currency($this->dividends, 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Options (Net)') }}</div>
            <div class="text-xl font-bold {{ $this->optionsIncome >= 0 ? 'text-success' : 'text-error' }} mt-1">{{ Number::currency($this->optionsIncome, 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Monthly Avg') }}</div>
            <div class="text-xl font-bold mt-1">{{ Number::currency($this->totalIncome / 12, 'USD') }}</div>
        </x-ui.card>
    </div>

    {{-- Monthly stacked chart --}}
    <x-ui.card title="{{ __('Monthly Income') }}" class="mb-6">
        <div class="overflow-x-auto">
            <div class="flex items-end gap-1 h-48 px-2">
                @foreach($this->monthlyBreakdown as $month)
                    @php
                        $maxVal = collect($this->monthlyBreakdown)->max('total') ?: 1;
                        $divH = $maxVal > 0 ? ($month['dividends'] / $maxVal) * 100 : 0;
                        $optH = $maxVal > 0 ? (max(0, $month['options']) / $maxVal) * 100 : 0;
                    @endphp
                    <div class="flex-1 flex flex-col items-center gap-1">
                        <div class="w-full flex gap-0.5 items-end justify-center h-36">
                            <div class="w-3 bg-info/80 rounded-t transition-all" style="height: {{ $divH }}%"></div>
                            <div class="w-3 bg-accent/80 rounded-t transition-all" style="height: {{ $optH }}%"></div>
                        </div>
                        <span class="text-xs text-base-content/60">{{ $month['month'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="flex items-center gap-4 mt-3 text-xs text-base-content/60">
            <span class="flex items-center gap-1"><span class="w-3 h-3 bg-info/80 rounded"></span> {{ __('Dividends') }}</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 bg-accent/80 rounded"></span> {{ __('Options') }}</span>
        </div>
    </x-ui.card>

    {{-- Top income symbols --}}
    <x-ui.card title="{{ __('Top Income Symbols') }}">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('Symbol') }}</th>
                        <th>{{ __('Source') }}</th>
                        <th class="text-right">{{ __('Income') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->topIncomeSymbols as $row)
                        <tr class="hover">
                            <td class="font-semibold">{{ $row['symbol'] }}</td>
                            <td><span class="badge badge-sm badge-accent">{{ $row['source'] }}</span></td>
                            <td class="text-right text-success font-medium">{{ Number::currency($row['income'], 'USD') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-base-content/50">{{ __('No income data') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
