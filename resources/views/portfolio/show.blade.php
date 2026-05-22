@use('App\Models\Currency')

<x-layouts.app>
    <style>
        .stat-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; }
        @media (min-width: 48rem) { .stat-grid { grid-template-columns: repeat(5, 1fr); } }
        .gain-pos { color: #4ade80 !important; }
        .gain-neg { color: #f87171 !important; }
    </style>
    @php
        $ptDisplayTitle = $portfolio->title;
        $unrealized = $metrics->get('total_market_gain_dollars', 0);
        $costBasis = $metrics->get('total_cost_basis', 0);
        $unrealizedPct = $costBasis > 0 ? ($unrealized / $costBasis) * 100 : 0;
        $yearsHist = $capitalMetrics['years_of_history'] ?? 0;
        // CAGR: annualize the cost-basis-based return
        $cagr = null;
        if ($yearsHist >= 1 && $costBasis > 0) {
            $cagr = (pow(1 + $unrealizedPct / 100, 1 / $yearsHist) - 1) * 100;
        }
    @endphp
    <div x-data>

        <x-ui.modal 
            key="create-transaction"
            title="{{ __('Create Transaction') }}"
        >
            @livewire('manage-transaction-form', [
                'portfolio' => $portfolio, 
            ])

        </x-ui.modal>

        <x-ui.drawer 
            key="manage-portfolio"
            title="{{ __('Manage Portfolio') }}"
        >
            @livewire('manage-portfolio-form', [
                'portfolio' => $portfolio, 
                'hideCancel' => true
            ])

        </x-ui.drawer>

        <x-ui.toolbar :title="$ptDisplayTitle">

            @if($portfolio->wishlist)
            <x-ui.badge value="{{ __('Wishlist') }}" title="{{ __('Wishlist') }}" class="badge-secondary badge-outline mr-3" />
            @endif

            @if(auth()->user()->id !== $portfolio->owner_id)
            <x-ui.badge value="{{ $portfolio->owner->name }}" title="{{ __('Owner').': '.$portfolio->owner->name }}" class="badge-secondary badge-outline mr-3" />
            @endif

            @can('fullAccess', $portfolio)
            <x-ui.button 
                title="{{ __('Manage Portfolio') }}" 
                icon="o-pencil" 
                class="btn-circle btn-ghost btn-sm text-secondary" 
                @click="$dispatch('toggle-manage-portfolio')"
            />
            @else
            <x-ui.icon name="o-eye" class="text-secondary w-4" title="{{ __('Read only') }}" />
            @endcan

            <x-ui.flex-spacer />
            
            @can('fullAccess', $portfolio)
            <div>
                <x-ui.button 
                    label="{{ __('Create Transaction') }}" 
                    class="btn-sm btn-primary whitespace-nowrap" 
                    @click="$dispatch('toggle-create-transaction')"
                />
            </div>
            @endcan
        </x-ui.toolbar>

        {{-- Compact Stat Bar --}}
        <div class="mt-3 rounded-2xl border border-base-300 bg-base-100 shadow-sm overflow-hidden">
            <div class="stat-grid">
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Account Value') }}</div>
                    <div class="mt-1 text-xl font-black leading-tight">{{ Number::currency($metrics->get('total_market_value', 0)) }}</div>
                    @if($portfolio->broker_value_updated_at)
                    <div class="mt-1 text-xs text-base-content/40">{{ $portfolio->broker_value_updated_at->format('M j, g:i A') }}</div>
                    @endif
                </div>
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Cost Basis') }}</div>
                    <div class="mt-1 text-xl font-black">{{ Number::currency($costBasis) }}</div>
                </div>
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Gain / Loss') }}</div>
                    <div class="mt-1 text-xl font-black {{ $unrealized >= 0 ? 'gain-pos' : 'gain-neg' }}">{{ Number::currency($unrealized) }}</div>
                </div>
                <div class="p-4 border-b md:border-b-0 md:border-r border-base-300">
                    <div class="text-xs font-medium text-base-content/60">{{ __('Return') }}</div>
                    <div class="mt-1 text-xl font-black {{ $unrealizedPct >= 0 ? 'gain-pos' : 'gain-neg' }}">{{ number_format($unrealizedPct, 1) }}%</div>
                </div>
                <div class="p-4">
                    <div class="text-xs font-medium text-base-content/60">{{ __('CAGR') }}</div>
                    @if($cagr !== null)
                    <div class="mt-1 text-xl font-black {{ $cagr >= 0 ? 'gain-pos' : 'gain-neg' }}">{{ number_format($cagr, 1) }}%</div>
                    @else
                    <div class="mt-1 text-xl font-black text-base-content/40">&mdash;</div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Performance Chart --}}
        <div class="mt-4">
        @livewire('portfolio-performance-chart', [
            'name' => 'portfolio-'.$portfolio->id,
            'portfolio' => $portfolio
        ])
        </div>

        {{-- Holdings Table (full width — the main event) --}}
        <div class="mt-6">
            <x-ui.card title="{{ __('Holdings') }}" class="overflow-hidden">

                @if($portfolio->holdings->where('quantity', '>', 0)->isEmpty())
                    <div class="flex justify-center items-center h-full pb-10 text-secondary">
                        {{ __('Nothing to show here yet') }}
                    </div>
                @else
                @livewire('tables.holdings-table', [
                    'portfolio' => $portfolio
                ])
                @endif
            </x-ui.card>
        </div>

        {{-- Activity + Top Performers --}}
        <div class="mt-6 grid md:grid-cols-7 gap-5">

            <x-ui.card title="{{ __('Recent activity') }}" class="col-span-7 md:col-span-4">

                @if($portfolio->transactions->isEmpty())
                    <div class="flex justify-center items-center h-full pb-10 text-secondary">
                        {{ __('Nothing to show here yet') }}
                    </div>
                @endif

                @livewire('transactions-list', [
                    'portfolio' => $portfolio,
                    'transactions' => $portfolio->transactions
                ])

            </x-ui.card>

            <x-ui.card title="{{ __('Top performers') }}" class="col-span-7 md:col-span-3">

                @if($portfolio->holdings->isEmpty())
                    <div class="flex justify-center items-center h-full pb-10 text-secondary">
                        {{ __('Nothing to show here yet') }}
                    </div>
                @endif

                @livewire('top-performers-list', [
                    'holdings' => $portfolio->holdings
                ])

            </x-ui.card>

            @if(config('services.ai_chat_enabled'))
            @livewire('ui.ai-chat-window', [
                'chatable' => $portfolio,
                'suggested_prompts' => [
                    [
                        'text' => 'Which holding is most successful?',
                        'value' => 'Which holding is most successful in this portfolio?',
                    ],
                    [
                        'text' => 'Should I diversify more?',
                        'value' => 'Is my portfolio diverse enough?',
                    ],
                    [
                        'text' => 'Analyze my portfolio?',
                        'value' => 'Can you analyze my portfolio for risks or opportunities?',
                    ]
                ],
            ])
            @endif

        </div>
    </div>
</x-layouts.app>