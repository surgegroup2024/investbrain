<x-layouts.app>
    <style>
        .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; }
        @media (min-width: 48rem) { .stat-grid { grid-template-columns: repeat(5, 1fr); } }
        .gain-pos { color: #4ade80 !important; }
        .gain-neg { color: #f87171 !important; }
    </style>

        <x-ui.toolbar title="{{ __('Dashboard') }}"></x-ui.toolbar>

        {{-- Compact Stat Bar --}}
        <div class="mt-3 rounded-2xl border border-base-300 bg-base-100 shadow-sm overflow-hidden">
            <div class="stat-grid">
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Total Value') }}</div>
                    <div class="mt-1 text-xl font-black leading-tight">{{ Number::currency($metrics->get('total_market_value', 0)) }}</div>
                    @if($accountValueAsOf)
                    <div class="mt-1 text-xs text-base-content/40">{{ \Illuminate\Support\Carbon::parse($accountValueAsOf)->format('M j, g:i A') }}</div>
                    @endif
                </div>
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Net Cash In') }}</div>
                    <div class="mt-1 text-xl font-black">{{ Number::currency($capitalDeployed) }}</div>
                </div>
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Total Profit') }}</div>
                    <div class="mt-1 text-xl font-black {{ $totalProfit >= 0 ? 'gain-pos' : 'gain-neg' }}">{{ Number::currency($totalProfit) }}</div>
                </div>
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Return') }}</div>
                    <div class="mt-1 text-xl font-black {{ $totalReturn >= 0 ? 'gain-pos' : 'gain-neg' }}">{{ number_format($totalReturn, 1) }}%</div>
                </div>
                <div class="p-4" title="{{ $cagr !== null ? 'CAGR = (Current Value / Net Cash In)^(1/Years) - 1 = ('.Number::currency($totalMarketValue).' / '.Number::currency($capitalDeployed).')^(1/'.number_format($cagrYears, 1).'y) - 1 = '.number_format($cagr, 1).'%' : 'Need ≥1 year of history and positive net cash in' }}">
                    <div class="text-xs font-medium text-base-content/60">{{ __('CAGR') }}</div>
                    @if($cagr !== null)
                    <div class="mt-1 text-xl font-black {{ $cagr >= 0 ? 'gain-pos' : 'gain-neg' }}">{{ number_format($cagr, 1) }}%</div>
                    <div class="mt-1 text-xs text-base-content/40">{{ number_format($cagrYears, 1) }} years</div>
                    @else
                    <div class="mt-1 text-xl font-black text-base-content/40">&mdash;</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Performance Chart --}}
        <div class="mt-4">
        @livewire('portfolio-performance-chart', [
            'name' => 'dashboard'
        ])
        </div>

        {{-- My Portfolios (full width — the navigation hub) --}}
        <div class="mt-6">
            <x-ui.card title="{{ __('My Portfolios') }}">

                @if ($user->portfolios->isEmpty())
                    <div class="flex justify-center items-center h-[100px] mb-8">
                        <x-ui.button label="{{ __('Import / Export Data') }}" class="btn-primary btn-outline mr-6" link="{{ route('import-export') }}" />
                        <span>{{ __('or') }}</span>
                        <x-ui.button label="{{ __('Create your first portfolio!') }}" class="btn-primary ml-6" link="{{ route('portfolio.create') }}" />
                    </div>
                @endif
                
                @foreach($user->portfolios as $portfolio)
                    <x-ui.list-item no-separator :item="$portfolio" link="{{ route('portfolio.show', ['portfolio' => $portfolio->id]) }}">
                        <x-slot:value class="flex items-center justify-between w-full">
                            <span>
                                <span class="font-semibold">{{ $portfolio->title }}</span>
                                @if($portfolio->wishlist)
                                    <x-ui.badge value="{{ __('Wishlist') }}" class="badge-secondary badge-outline badge-sm ml-2" />
                                @endif
                            </span>
                            @if($portfolio->broker_value)
                                <span class="text-sm text-base-content/60 tabular-nums">${{ number_format($portfolio->broker_value, 0) }}</span>
                            @endif
                        </x-slot:value>
                    </x-ui.list-item>
                @endforeach

            </x-ui.card>
        </div>

        {{-- Bottom grid: Activity + Performers + Losers --}}
        <div class="mt-6 grid md:grid-cols-7 gap-5">

            @if (!$user->transactions->isEmpty())
            <x-ui.card title="{{ __('Recent activity') }}" class="col-span-7 md:col-span-4">

                @php
                    $recentTx = $user->transactions
                        ->whereIn('transaction_type', ['BUY', 'SELL'])
                        ->where(fn ($t) => !empty($t->symbol))
                        ->sortByDesc('date')
                        ->take(15)
                        ->values();
                @endphp

                @if ($recentTx->isEmpty())
                    <div class="text-sm text-base-content/50 py-4 text-center">{{ __('No recent buy/sell activity') }}</div>
                @else
                @livewire('transactions-list', [
                    'transactions' => $recentTx,
                    'showPortfolio' => true,
                    'paginate' => false
                ])
                @endif

            </x-ui.card>
            @endif

            @if (!$user->portfolios->isEmpty())
            <x-ui.card title="{{ __('Top performers') }}" class="col-span-7 md:col-span-3">

                @livewire('top-performers-list', [
                    'holdings' => $user->holdings
                ])

            </x-ui.card>

            <x-ui.card title="{{ __('Top losers') }}" class="col-span-7 md:col-span-3">

                @livewire('top-losers-list', [
                    'holdings' => $user->holdings
                ])

            </x-ui.card>
            @endif

        </div>
    
</x-layouts.app>