<?php

use App\Models\OptionActivity;
use Illuminate\Support\Collection;
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

    public function getSummaryProperty(): array
    {
        $query = OptionActivity::whereIn('portfolio_id', $this->portfolioIds);

        if ($this->year) {
            $query->whereYear('date', $this->year);
        }

        $received = (clone $query)->premiumReceived()->sum('total_premium');
        $paid = (clone $query)->premiumPaid()->sum('total_premium');
        $totalTrades = (clone $query)->count();
        $winningTrades = (clone $query)->where('total_premium', '>', 0)
            ->whereIn('action', ['SELL_TO_OPEN', 'SELL_TO_CLOSE'])->count();

        return [
            'received' => abs($received),
            'paid' => abs($paid),
            'net' => abs($received) - abs($paid),
            'total_trades' => $totalTrades,
            'win_rate' => $totalTrades > 0 ? round(($winningTrades / $totalTrades) * 100) : 0,
        ];
    }

    public function getBySymbolProperty(): Collection
    {
        $query = OptionActivity::whereIn('portfolio_id', $this->portfolioIds);

        if ($this->year) {
            $query->whereYear('date', $this->year);
        }

        return $query->get()
            ->groupBy('symbol')
            ->map(function ($activities, $symbol) {
                $received = $activities->whereIn('action', ['SELL_TO_OPEN', 'SELL_TO_CLOSE'])->sum('total_premium');
                $paid = $activities->whereIn('action', ['BUY_TO_OPEN', 'BUY_TO_CLOSE'])->sum('total_premium');

                return [
                    'symbol' => $symbol,
                    'contracts' => $activities->sum('contracts'),
                    'received' => abs($received),
                    'paid' => abs($paid),
                    'net' => abs($received) - abs($paid),
                    'trades' => $activities->count(),
                ];
            })
            ->sortByDesc('net')
            ->values();
    }

    public function getMonthlyDataProperty(): array
    {
        $query = OptionActivity::whereIn('portfolio_id', $this->portfolioIds);

        if ($this->year) {
            $query->whereYear('date', $this->year);
        }

        $activities = $query->get();

        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthActivities = $activities->filter(fn ($a) => $a->date->month === $m);
            $received = abs($monthActivities->whereIn('action', ['SELL_TO_OPEN', 'SELL_TO_CLOSE'])->sum('total_premium'));
            $paid = abs($monthActivities->whereIn('action', ['BUY_TO_OPEN', 'BUY_TO_CLOSE'])->sum('total_premium'));

            $monthly[] = [
                'month' => date('M', mktime(0, 0, 0, $m, 1)),
                'received' => $received,
                'paid' => $paid,
                'net' => $received - $paid,
            ];
        }

        return $monthly;
    }

    public function getAvailableYearsProperty(): array
    {
        return OptionActivity::whereIn('portfolio_id', $this->portfolioIds)
            ->selectRaw('DISTINCT EXTRACT(YEAR FROM date) as year')
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($y) => (string) intval($y))
            ->toArray();
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
            <div class="text-sm text-base-content/60">{{ __('Premium Received') }}</div>
            <div class="text-xl font-bold text-success mt-1">{{ Number::currency($this->summary['received'], 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Premium Paid') }}</div>
            <div class="text-xl font-bold text-error mt-1">{{ Number::currency($this->summary['paid'], 'USD') }}</div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Net Income') }}</div>
            <div class="text-xl font-bold {{ $this->summary['net'] >= 0 ? 'text-success' : 'text-error' }} mt-1">
                {{ Number::currency($this->summary['net'], 'USD') }}
            </div>
        </x-ui.card>
        <x-ui.card dense>
            <div class="text-sm text-base-content/60">{{ __('Total Trades') }}</div>
            <div class="text-xl font-bold mt-1">{{ number_format($this->summary['total_trades']) }}</div>
        </x-ui.card>
    </div>

    {{-- Monthly chart --}}
    <x-ui.card title="{{ __('Monthly Premium P&L') }}" class="mb-6">
        <div class="overflow-x-auto">
            <div class="flex items-end gap-1 h-48 px-2">
                @foreach($this->monthlyData as $month)
                    @php
                        $maxVal = collect($this->monthlyData)->max('received') ?: 1;
                        $receivedH = ($month['received'] / $maxVal) * 100;
                        $paidH = ($month['paid'] / $maxVal) * 100;
                    @endphp
                    <div class="flex-1 flex flex-col items-center gap-1">
                        <div class="w-full flex gap-0.5 items-end justify-center h-36">
                            <div class="w-3 bg-success/80 rounded-t transition-all" style="height: {{ $receivedH }}%"></div>
                            <div class="w-3 bg-error/80 rounded-t transition-all" style="height: {{ $paidH }}%"></div>
                        </div>
                        <span class="text-xs text-base-content/60">{{ $month['month'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="flex items-center gap-4 mt-3 text-xs text-base-content/60">
            <span class="flex items-center gap-1"><span class="w-3 h-3 bg-success/80 rounded"></span> {{ __('Received') }}</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 bg-error/80 rounded"></span> {{ __('Paid') }}</span>
        </div>
    </x-ui.card>

    {{-- By symbol table --}}
    <x-ui.card title="{{ __('By Symbol') }}">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('Symbol') }}</th>
                        <th class="text-right">{{ __('Trades') }}</th>
                        <th class="text-right">{{ __('Contracts') }}</th>
                        <th class="text-right">{{ __('Received') }}</th>
                        <th class="text-right">{{ __('Paid') }}</th>
                        <th class="text-right">{{ __('Net') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->bySymbol as $row)
                        <tr class="hover">
                            <td class="font-semibold">{{ $row['symbol'] }}</td>
                            <td class="text-right">{{ $row['trades'] }}</td>
                            <td class="text-right">{{ number_format($row['contracts']) }}</td>
                            <td class="text-right text-success">{{ Number::currency($row['received'], 'USD') }}</td>
                            <td class="text-right text-error">{{ Number::currency($row['paid'], 'USD') }}</td>
                            <td class="text-right font-medium {{ $row['net'] >= 0 ? 'text-success' : 'text-error' }}">
                                {{ Number::currency($row['net'], 'USD') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
