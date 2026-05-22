<?php

use App\Models\CashFlow;
use App\Models\OptionActivity;
use App\Models\Portfolio;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Livewire\Volt\Component;

new class extends Component
{
    // filters
    public string $filterBrokerage = '';

    public string $filterType = '';

    public string $filterSymbol = '';

    public string $filterDateFrom = '';

    public string $filterDateTo = '';

    public int $perPage = 30;

    public int $page = 1;

    protected $listeners = [
        'cash-flow-saved' => '$refresh',
    ];

    public function updatedFilterBrokerage(): void
    {
        $this->page = 1;
    }

    public function updatedFilterType(): void
    {
        $this->page = 1;
    }

    public function updatedFilterSymbol(): void
    {
        $this->page = 1;
    }

    public function updatedFilterDateFrom(): void
    {
        $this->page = 1;
    }

    public function updatedFilterDateTo(): void
    {
        $this->page = 1;
    }

    public function loadMore(): void
    {
        $this->page++;
    }

    public function resetFilters(): void
    {
        $this->filterBrokerage = '';
        $this->filterType = '';
        $this->filterSymbol = '';
        $this->filterDateFrom = '';
        $this->filterDateTo = '';
        $this->page = 1;
    }

    public function getActivitiesProperty(): Collection
    {
        $userPortfolios = auth()->user()->portfolios;
        $portfolioIds = $userPortfolios->pluck('id');
        $portfolioMap = $userPortfolios->keyBy('id');

        $activities = collect();

        // Get transactions (BUY/SELL)
        if (! $this->filterType || in_array($this->filterType, ['BUY', 'SELL'])) {
            $txQuery = Transaction::query()
                ->whereIn('portfolio_id', $portfolioIds)
                ->with(['portfolio', 'market_data']);

            if ($this->filterType && in_array($this->filterType, ['BUY', 'SELL'])) {
                $txQuery->where('transaction_type', $this->filterType);
            }
            if ($this->filterSymbol) {
                $txQuery->where('symbol', 'ILIKE', '%'.$this->filterSymbol.'%');
            }
            if ($this->filterDateFrom) {
                $txQuery->where('date', '>=', $this->filterDateFrom);
            }
            if ($this->filterDateTo) {
                $txQuery->where('date', '<=', $this->filterDateTo);
            }
            if ($this->filterBrokerage) {
                $brokeragePortfolioIds = $userPortfolios
                    ->filter(fn ($p) => str_contains(strtolower($p->title), strtolower($this->filterBrokerage)))
                    ->pluck('id');
                $txQuery->whereIn('portfolio_id', $brokeragePortfolioIds);
            }

            $transactions = $txQuery->get()->map(function ($tx) use ($portfolioMap) {
                return [
                    'date' => $tx->date->toDateString(),
                    'datetime' => $tx->date->toDateTimeString(),
                    'type' => $tx->split ? 'SPLIT' : ($tx->reinvested_dividend ? 'REINVEST' : $tx->transaction_type),
                    'category' => 'transaction',
                    'symbol' => $tx->symbol,
                    'description' => $tx->quantity.' shares @ '.number_format($tx->transaction_type === 'BUY' ? ($tx->cost_basis ?? 0) : ($tx->sale_price ?? 0), 2),
                    'amount' => $tx->transaction_type === 'BUY'
                        ? -($tx->cost_basis ?? 0) * $tx->quantity
                        : ($tx->sale_price ?? 0) * $tx->quantity,
                    'portfolio' => $portfolioMap[$tx->portfolio_id]?->title ?? 'Unknown',
                    'source' => 'synced',
                ];
            });

            $activities = $activities->merge($transactions);
        }

        // Get cash flows (DEPOSIT/WITHDRAWAL)
        if (! $this->filterType || in_array($this->filterType, ['DEPOSIT', 'WITHDRAWAL'])) {
            $cfQuery = CashFlow::query()
                ->whereIn('portfolio_id', $portfolioIds)
                ->with('portfolio');

            if ($this->filterType && in_array($this->filterType, ['DEPOSIT', 'WITHDRAWAL'])) {
                $cfQuery->where('type', $this->filterType);
            }
            if ($this->filterSymbol) {
                // cash flows don't have symbols, skip if symbol filter is active
                $cfQuery->whereRaw('1 = 0');
            }
            if ($this->filterDateFrom) {
                $cfQuery->where('date', '>=', $this->filterDateFrom);
            }
            if ($this->filterDateTo) {
                $cfQuery->where('date', '<=', $this->filterDateTo);
            }
            if ($this->filterBrokerage) {
                $brokeragePortfolioIds = $userPortfolios
                    ->filter(fn ($p) => str_contains(strtolower($p->title), strtolower($this->filterBrokerage)))
                    ->pluck('id');
                $cfQuery->whereIn('portfolio_id', $brokeragePortfolioIds);
            }

            $cashFlows = $cfQuery->get()->map(function ($cf) use ($portfolioMap) {
                $isManual = empty($cf->external_id);

                return [
                    'date' => $cf->date->toDateString(),
                    'datetime' => $cf->date->toDateTimeString(),
                    'type' => $cf->type,
                    'category' => 'cashflow',
                    'symbol' => '',
                    'description' => $cf->description ?? $cf->type,
                    'amount' => $cf->type === 'DEPOSIT' ? $cf->amount : -$cf->amount,
                    'portfolio' => $portfolioMap[$cf->portfolio_id]?->title ?? 'Unknown',
                    'source' => $isManual ? 'manual' : 'synced',
                ];
            });

            $activities = $activities->merge($cashFlows);
        }

        // Get option activities
        if (! $this->filterType || in_array($this->filterType, ['OPTIONS'])) {
            $optQuery = OptionActivity::query()
                ->whereIn('portfolio_id', $portfolioIds)
                ->with('portfolio');

            if ($this->filterSymbol) {
                $optQuery->where('symbol', 'ILIKE', '%'.$this->filterSymbol.'%');
            }
            if ($this->filterDateFrom) {
                $optQuery->where('date', '>=', $this->filterDateFrom);
            }
            if ($this->filterDateTo) {
                $optQuery->where('date', '<=', $this->filterDateTo);
            }
            if ($this->filterBrokerage) {
                $brokeragePortfolioIds = $userPortfolios
                    ->filter(fn ($p) => str_contains(strtolower($p->title), strtolower($this->filterBrokerage)))
                    ->pluck('id');
                $optQuery->whereIn('portfolio_id', $brokeragePortfolioIds);
            }

            $options = $optQuery->get()->map(function ($opt) use ($portfolioMap) {
                $actionLabel = str_replace('_', ' ', $opt->action);

                return [
                    'date' => $opt->date->toDateString(),
                    'datetime' => $opt->date->toDateTimeString(),
                    'type' => 'OPTIONS',
                    'category' => 'options',
                    'symbol' => $opt->symbol,
                    'description' => $actionLabel.' '.$opt->contracts.'x '.$opt->option_type.' $'.number_format($opt->strike_price, 2),
                    'amount' => $opt->total_premium,
                    'portfolio' => $portfolioMap[$opt->portfolio_id]?->title ?? 'Unknown',
                    'source' => 'synced',
                ];
            });

            $activities = $activities->merge($options);
        }

        // Sort by date descending and paginate
        return $activities
            ->sortByDesc('datetime')
            ->values()
            ->take($this->page * $this->perPage);
    }

    public function getTotalCountProperty(): int
    {
        // Rough count for "load more" logic
        $portfolioIds = auth()->user()->portfolios->pluck('id');
        $count = 0;

        if (! $this->filterType || in_array($this->filterType, ['BUY', 'SELL'])) {
            $count += Transaction::whereIn('portfolio_id', $portfolioIds)->count();
        }
        if (! $this->filterType || in_array($this->filterType, ['DEPOSIT', 'WITHDRAWAL'])) {
            $count += CashFlow::whereIn('portfolio_id', $portfolioIds)->count();
        }
        if (! $this->filterType || in_array($this->filterType, ['OPTIONS'])) {
            $count += OptionActivity::whereIn('portfolio_id', $portfolioIds)->count();
        }

        return $count;
    }
}; ?>

<div>
    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <select wire:model.live="filterBrokerage" class="select select-sm select-bordered">
            <option value="">{{ __('All Accounts') }}</option>
            @foreach(auth()->user()->portfolios as $portfolio)
                <option value="{{ $portfolio->title }}">{{ $portfolio->title }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterType" class="select select-sm select-bordered">
            <option value="">{{ __('All Types') }}</option>
            <option value="BUY">{{ __('Buy') }}</option>
            <option value="SELL">{{ __('Sell') }}</option>
            <option value="DEPOSIT">{{ __('Deposit') }}</option>
            <option value="WITHDRAWAL">{{ __('Withdrawal') }}</option>
            <option value="OPTIONS">{{ __('Options') }}</option>
        </select>

        <input
            type="text"
            wire:model.live.debounce.300ms="filterSymbol"
            placeholder="{{ __('Search symbol...') }}"
            class="input input-sm input-bordered w-36"
        />

        <input
            type="date"
            wire:model.live="filterDateFrom"
            class="input input-sm input-bordered"
        />
        <span class="text-base-content/60">{{ __('to') }}</span>
        <input
            type="date"
            wire:model.live="filterDateTo"
            class="input input-sm input-bordered"
        />

        @if($filterBrokerage || $filterType || $filterSymbol || $filterDateFrom || $filterDateTo)
            <x-ui.button
                label="{{ __('Clear') }}"
                icon="o-x-mark"
                class="btn-sm btn-ghost"
                wire:click="resetFilters"
            />
        @endif
    </div>

    {{-- Activity List --}}
    <x-ui.card>
        @php $currentDate = ''; @endphp
        @forelse($this->activities as $activity)
            @if($activity['date'] !== $currentDate)
                @php $currentDate = $activity['date']; @endphp
                <div class="px-4 py-2 bg-base-200 text-sm font-medium text-base-content/70 {{ ! $loop->first ? 'mt-3' : '' }} -mx-5 first:-mt-5">
                    {{ \Carbon\Carbon::parse($currentDate)->format('F j, Y') }}
                </div>
            @endif

            <div class="flex items-center gap-3 px-4 py-3 hover:bg-base-200/50 transition-colors {{ ! $loop->last ? 'border-b border-base-200' : '' }}">
                {{-- Type badge --}}
                <div class="shrink-0">
                    @switch($activity['type'])
                        @case('BUY')
                            <span class="badge badge-success badge-sm">BUY</span>
                            @break
                        @case('SELL')
                            <span class="badge badge-error badge-sm">SELL</span>
                            @break
                        @case('DEPOSIT')
                            <span class="badge badge-info badge-sm">DEP</span>
                            @break
                        @case('WITHDRAWAL')
                            <span class="badge badge-warning badge-sm">WDR</span>
                            @break
                        @case('OPTIONS')
                            <span class="badge badge-accent badge-sm">OPT</span>
                            @break
                        @case('SPLIT')
                            <span class="badge badge-neutral badge-sm">SPLIT</span>
                            @break
                        @case('REINVEST')
                            <span class="badge badge-neutral badge-sm">REINV</span>
                            @break
                        @default
                            <span class="badge badge-ghost badge-sm">{{ $activity['type'] }}</span>
                    @endswitch
                </div>

                {{-- Symbol & description --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        @if($activity['symbol'])
                            <span class="font-semibold text-base-content">{{ $activity['symbol'] }}</span>
                        @endif
                        <span class="text-sm text-base-content/70 truncate">{{ $activity['description'] }}</span>
                    </div>
                    <div class="text-xs text-base-content/50 flex items-center gap-2 mt-0.5">
                        <span>{{ $activity['portfolio'] }}</span>
                        @if($activity['source'] === 'manual')
                            <span class="badge badge-outline badge-xs">{{ __('manual') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Amount --}}
                <div class="shrink-0 text-right">
                    <span class="font-medium {{ $activity['amount'] >= 0 ? 'text-success' : 'text-error' }}">
                        {{ $activity['amount'] >= 0 ? '+' : '' }}{{ Number::currency(abs($activity['amount']), 'USD') }}
                    </span>
                </div>
            </div>
        @empty
            <div class="text-center py-12 text-base-content/50">
                <x-ui.icon name="o-inbox" class="w-12 h-12 mx-auto mb-3" />
                <p>{{ __('No activity found') }}</p>
            </div>
        @endforelse

        {{-- Load more --}}
        @if(count($this->activities) < $this->totalCount)
            <div class="text-center py-4 border-t border-base-200">
                <x-ui.button
                    label="{{ __('Load More') }}"
                    class="btn-sm btn-ghost"
                    wire:click="loadMore"
                    wire:loading.attr="disabled"
                />
            </div>
        @endif
    </x-ui.card>
</div>
