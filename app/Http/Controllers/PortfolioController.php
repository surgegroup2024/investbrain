<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Holding;
use App\Models\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PortfolioController extends Controller
{
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('portfolio.create');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Portfolio $portfolio)
    {
        Gate::authorize('readOnly', $portfolio);

        $portfolio->load(['transactions', 'holdings']);

        // get portfolio metrics
        $metrics = cache()->tags(['metrics-'.$request->user()->id])->remember(
            'portfolio-metrics-'.$portfolio->id,
            60,
            function () use ($portfolio) {
                $m = Holding::query()
                    ->portfolio($portfolio->id)
                    ->getPortfolioMetrics();

                // Adjust market value/gain if broker_value is authoritative
                if ($portfolio->broker_value && abs($portfolio->broker_value - $m->get('total_market_value', 0)) > $portfolio->broker_value * 0.1) {
                    $m->put('total_market_value', $portfolio->broker_value);
                    $m->put('total_market_gain_dollars', $portfolio->broker_value - $m->get('total_cost_basis', 0));
                }

                return $m;
            }
        );

        // capital metrics (deposits/withdrawals/IRR)
        $capitalMetrics = cache()->tags(['metrics-'.$request->user()->id])->remember(
            'capital-metrics-'.$portfolio->id,
            60,
            function () use ($portfolio) {
                return $portfolio->capitalMetrics();
            }
        );

        // options income (net premium: sold - bought)
        $optionsIncome = cache()->tags(['metrics-'.$request->user()->id])->remember(
            'options-income-'.$portfolio->id,
            60,
            function () use ($portfolio) {
                $sold = (float) $portfolio->optionActivities()
                    ->whereIn('action', ['SELL_TO_OPEN', 'SELL_TO_CLOSE'])
                    ->sum('total_premium');
                $bought = (float) $portfolio->optionActivities()
                    ->whereIn('action', ['BUY_TO_OPEN', 'BUY_TO_CLOSE'])
                    ->sum('total_premium');

                return $sold - $bought;
            }
        );

        $formattedHoldings = $portfolio->getFormattedHoldings();

        return view('portfolio.show', compact(['portfolio', 'metrics', 'capitalMetrics', 'optionsIncome', 'formattedHoldings']));
    }
}
