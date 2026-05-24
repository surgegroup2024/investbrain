<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Holding;
use App\Models\OptionActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        $user = $request->user()->load(['portfolios', 'holdings', 'transactions']);
        $nonWishlistPortfolios = $user->portfolios->where('wishlist', false);

        // get portfolio metrics
        $metrics = cache()->tags(['metrics-'.$user->id])->remember(
            'dashboard-metrics-'.$user->id,
            10,
            function () use ($user, $nonWishlistPortfolios) {
                $m = Holding::query()
                    ->myHoldings()
                    ->withoutWishlists()
                    ->getPortfolioMetrics();

                // Prefer broker-reported total when available (matches portfolio list values)
                $brokerTotal = $nonWishlistPortfolios->sum('broker_value');

                if ($brokerTotal > 0) {
                    $m->put('total_market_value', $brokerTotal);
                    $m->put('total_market_gain_dollars', $brokerTotal - $m->get('total_cost_basis', 0));
                    $m->put('account_value_source', 'broker');
                } else {
                    $m->put('account_value_source', 'calculated');
                }

                return $m;
            }
        );

        // Income this month
        $portfolioIds = $user->portfolios->pluck('id');
        $monthStart = now()->startOfMonth();

        $optionsIncomeThisMonth = (float) OptionActivity::whereIn('portfolio_id', $portfolioIds)
            ->where('date', '>=', $monthStart)
            ->premiumReceived()
            ->sum('total_premium');

        // Actual dividends this month from dividends table
        $dividendsThisMonth = (float) DB::table('dividends')
            ->join('transactions as tx', function ($join) {
                $join->on('tx.symbol', '=', 'dividends.symbol')
                     ->on('tx.date', '<=', 'dividends.date');
            })
            ->whereIn('tx.portfolio_id', $portfolioIds)
            ->where('dividends.date', '>=', $monthStart)
            ->selectRaw("
                SUM(
                    (CASE WHEN tx.transaction_type = 'BUY' THEN tx.quantity ELSE 0 END
                    - CASE WHEN tx.transaction_type = 'SELL' THEN tx.quantity ELSE 0 END)
                    * dividends.dividend_amount
                ) as total
            ")->value('total') ?? 0;

        $capitalDeployed = (float) CashFlow::whereIn('portfolio_id', $portfolioIds)
            ->deposits()
            ->sum('amount') - (float) CashFlow::whereIn('portfolio_id', $portfolioIds)
            ->withdrawals()
            ->sum('amount');

        $incomeThisMonth = abs($optionsIncomeThisMonth) + max(0, $dividendsThisMonth);
        $optionsIncomeTotal = (float) OptionActivity::whereIn('portfolio_id', $portfolioIds)
            ->premiumReceived()
            ->sum('total_premium') - (float) OptionActivity::whereIn('portfolio_id', $portfolioIds)
            ->premiumPaid()
            ->sum('total_premium');

        $totalMarketValue = $metrics->get('total_market_value', 0);
        $totalReturn = $capitalDeployed > 0
            ? (($totalMarketValue - $capitalDeployed) / $capitalDeployed) * 100
            : 0;

        $totalProfit = $totalMarketValue - $capitalDeployed;
        $accountValueAsOf = $nonWishlistPortfolios
            ->pluck('broker_value_updated_at')
            ->filter()
            ->max();

        // CAGR: annualize the total return
        // If any portfolio has a performance_start_date override, use the earliest one
        // (oldest = highest number of years from today)
        $cagr = null;
        $cagrYears = null;
        if ($capitalDeployed > 0 && $totalReturn > -90 && $totalReturn < 2000) {
            $earliestOverride = $nonWishlistPortfolios
                ->whereNotNull('performance_start_date')
                ->pluck('performance_start_date')
                ->sort()
                ->first();

            if ($earliestOverride) {
                $years = Carbon::parse($earliestOverride)->diffInDays(Carbon::now()) / 365.25;
            } else {
                $firstFlow = CashFlow::whereIn('portfolio_id', $portfolioIds)
                    ->reorder('date', 'asc')
                    ->first();
                $years = $firstFlow ? $firstFlow->date->diffInDays(Carbon::now()) / 365.25 : 0;
            }

            if ($years >= 1) {
                $cagrYears = $years;
                $totalReturnDecimal = $totalReturn / 100;
                $cagr = (pow(1 + $totalReturnDecimal, 1 / $years) - 1) * 100;
            }
        }

        return view('dashboard', compact(
            'user',
            'metrics',
            'capitalDeployed',
            'incomeThisMonth',
            'totalReturn',
            'totalProfit',
            'totalMarketValue',
            'optionsIncomeTotal',
            'accountValueAsOf',
            'cagr',
            'cagrYears'
        ));
    }
}
