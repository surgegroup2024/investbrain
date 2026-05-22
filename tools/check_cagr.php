<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::where('email', 'positiveinvestor4@gmail.com')->first();
$portfolios = $user->portfolios()->where('wishlist', false)->get();

foreach ($portfolios as $p) {
    $m = $p->capitalMetrics();
    $modDietz = $m['modified_dietz_return'];
    $years = $m['years_of_history'];
    $cagr = 0;
    if ($years >= 1 && $modDietz > -100) {
        $cagr = (pow(1 + $modDietz / 100, 1 / $years) - 1) * 100;
    } elseif ($years > 0) {
        $cagr = $modDietz;
    }
    echo sprintf("%-30s | Deposits: %10s | Value: %10s | ModDietz: %6.1f%% | Years: %4.1f | CAGR: %6.1f%%\n",
        $p->title,
        number_format($m['total_deposits'], 0),
        number_format($m['current_value'], 0),
        $modDietz,
        $years,
        $cagr
    );
}

// Dashboard aggregate
$portfolioIds = $portfolios->pluck('id');
$capitalDeployed = (float) App\Models\CashFlow::whereIn('portfolio_id', $portfolioIds)
    ->deposits()->sum('amount')
    - (float) App\Models\CashFlow::whereIn('portfolio_id', $portfolioIds)
    ->withdrawals()->sum('amount');

$totalMV = App\Models\Holding::query()
    ->myHoldings()
    ->withoutWishlists()
    ->getPortfolioMetrics()->get('total_market_value', 0);

$brokerTotal = $portfolios->sum('broker_value');
if ($brokerTotal > 0 && abs($brokerTotal - $totalMV) > $brokerTotal * 0.1) {
    $totalMV = $brokerTotal;
}

$totalReturn = $capitalDeployed > 0 ? (($totalMV - $capitalDeployed) / $capitalDeployed) * 100 : 0;

$firstFlow = App\Models\CashFlow::whereIn('portfolio_id', $portfolioIds)
    ->reorder('date', 'asc')->first();
$years = $firstFlow ? $firstFlow->date->diffInDays(now()) / 365.25 : 0;
$dashCagr = 0;
if ($years >= 1) {
    $dashCagr = (pow(1 + $totalReturn / 100, 1 / $years) - 1) * 100;
}

echo "\n--- DASHBOARD ---\n";
echo sprintf("Capital Deployed: %s | Total MV: %s | Return: %.1f%% | Years: %.1f | CAGR: %.1f%%\n",
    number_format($capitalDeployed, 0),
    number_format($totalMV, 0),
    $totalReturn,
    $years,
    $dashCagr
);
