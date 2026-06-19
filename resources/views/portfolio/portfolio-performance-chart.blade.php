<?php

use App\Models\DailyChange;
use App\Models\Portfolio;
use Livewire\Attributes\Lazy;
use Livewire\Volt\Component;

new #[Lazy] class extends Component
{
    // props
    public ?Portfolio $portfolio = null;

    public string $name = 'portfolio';

    public string $scope = 'YTD';

    public array $scopeOptions = [
        ['id' => '1M', 'name' => '1 month', 'method' => 'subMonths', 'args' => [1]],
        ['id' => '3M', 'name' => '3 months', 'method' => 'subMonths', 'args' => [3]],
        ['id' => 'YTD', 'name' => 'Year to date', 'method' => 'startOfYear', 'args' => []],
        ['id' => '1Y', 'name' => '1 year', 'method' => 'subYears', 'args' => [1]],
        ['id' => '3Y', 'name' => '3 years', 'method' => 'subYears', 'args' => [3]],
        ['id' => 'ALL', 'name' => 'All time', 'method' => null],
    ];

    // data
    public array $chartSeries;

    // methods
    public function mount()
    {
        $this->chartSeries = $this->generatePerformanceData();
    }

    public function placeholder()
    {
        return <<<'HTML'
        <div class="skeleton h-[395px] mb-5"></div>
        HTML;
    }

    public function generatePerformanceData()
    {
        $filterMethod = collect($this->scopeOptions)->where('id', $this->scope)->first();

        $dailyChangeQuery = DailyChange::withDailyPerformance();

        if (isset($this->portfolio)) {

            // portfolio
            $dailyChangeQuery->portfolio($this->portfolio->id);

        } else {

            // dashboard
            $dailyChangeQuery->myDailyChanges()->withoutWishlists();
        }

        if ($filterMethod['method']) {

            $dailyChangeQuery->whereDate('daily_change.date', '>=', now()->{$filterMethod['method']}(...$filterMethod['args']));
        }

        $dailyChange = cache()->remember(
            'graph-'.$this->scope.'-'.(isset($this->portfolio) ? $this->portfolio->id : request()->user()->id),
            10,
            function () use ($dailyChangeQuery) {
                return $dailyChangeQuery->withMultipleDailyPerformance()->get();
            }
        );

        $marketValueData = [];
        $costBasisData = [];
        $marketGainData = [];

        foreach ($dailyChange as $data) {
            if (is_string($data)) {
                continue;
            }
            $date = $data->date;
            $marketGainData[] = [$date, round($data->total_market_gain, 2)];
            $marketValueData[] = [$date, round($data->total_market_value, 2)];
            $costBasisData[] = [$date, round($data->total_cost_basis, 2)];

            // $dividendSeries[] = [$date, round($data->total_dividends_earned, 2)];
            // $realizedGainSeries[] = [$date, round($data->realized_gains, 2)];
        }

        return [
            'series' => [
                [
                    'name' => __('Market Gain'),
                    'data' => $marketGainData,
                ],
                [
                    'name' => __('Market Value'),
                    'data' => $marketValueData,
                    'hidden' => true,
                ],
                [
                    'name' => __('Cost Basis'),
                    'data' => $costBasisData,
                    'hidden' => true,
                ],

                // [
                //     'name' => __('Dividends Earned'),
                //     'data' => $dividendSeries
                // ],
                // [
                //     'name' => __('Realized Gains'),
                //     'data' => $realizedGainSeries
                // ],
            ],
        ];
    }

    public function changeScope($scope)
    {
        $this->scope = $scope;

        cache()->forget('graph-'.$this->scope.'-'.(isset($this->portfolio) ? $this->portfolio->id : request()->user()->id));

        $this->chartSeries = $this->generatePerformanceData();
    }

    public function getScopeName($scope)
    {
        return collect($this->scopeOptions)->where('id', $scope)->first()['name'];
    }
}; ?>

<x-ui.card class="mb-6" x-data="{ collapsed: localStorage.getItem('chart-collapsed-{{ $name }}') === 'true' }">
    <div class="flex flex-col md:flex-row md:justify-between mb-2">
                    
        <div class="flex flex-col md:flex-row items-start md:items-center">
            
            <div class="flex items-center mb-2 md:mb-0 md:mr-4">
                <h2 class="text-xl">{{ __('Performance') }}</h2>
                <button
                    @click="collapsed = !collapsed; localStorage.setItem('chart-collapsed-{{ $name }}', collapsed)"
                    class="btn btn-ghost btn-xs btn-circle ml-2"
                    :title="collapsed ? '{{ __('Expand chart') }}' : '{{ __('Collapse chart') }}'"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 transition-transform" :class="collapsed ? '-rotate-90' : 'rotate-0'">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </button>
            </div>

            <div id="chart-legend-{{ $name }}" class="flex space-between whitespace-nowrap mb-2 md:mb-0" x-show="!collapsed" x-cloak></div>
            
        </div>
        
        <div class="flex items-center" x-data="{ loading: false }" x-show="!collapsed" x-cloak>
            {{-- <x-ui.button title="{{ __('Reset chart') }}" icon="o-arrow-path" class="btn-ghost btn-sm btn-circle mr-2" id="chart-reset-zoom-{{ $name }}" /> --}}

            <x-ui.loading x-show="loading" x-cloak class="text-gray-400 ml-2" />

            <x-ui.dropdown title="{{ __('Choose time period') }}" label="{{ $scope }}" class="btn-xs md:btn-sm btn-outline" x-bind:disabled="loading">
                    
                @foreach($scopeOptions as $option)

                    <x-ui.menu-item 
                        title="{{ $option['name'] }}" 
                        @click="
                            timeout = setTimeout(() => { loading = true }, 200);
                            $wire.changeScope('{{ $option['id'] }}').then(() => {
                                clearTimeout(timeout);
                                loading = false;
                            })
                        "
                    />
            
                @endforeach

            </x-dropdown>
        </div>
    </div>

    <div
        class="h-[280px] mb-5"
        x-show="!collapsed"
        x-collapse
    >
        <x-ui.apex-chart :series-data="$chartSeries" :name="$name" />
    </div>

</x-ui.card>