<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Holding;
use App\Models\Portfolio;
use App\Models\User;

$user = User::first();
auth()->login($user);
$portfolios = $user->portfolios->where('wishlist', false);

echo "=== Broker-reported values ===\n";
$brokerTotal = 0;
foreach ($portfolios as $p) {
    $bv = $p->broker_value ?? 0;
    $brokerTotal += $bv;
    echo sprintf("  %-30s  broker_value=$%s\n", $p->title, number_format($bv, 2));
}
echo sprintf("  BROKER TOTAL:                   $%s\n\n", number_format($brokerTotal, 2));

echo "=== Official getPortfolioMetrics() ===\n";
$m = Holding::query()->myHoldings()->withoutWishlists()->getPortfolioMetrics();
echo "  total_market_value:  $" . number_format($m->get('total_market_value', 0), 2) . "\n";
echo "  total_cost_basis:    $" . number_format($m->get('total_cost_basis', 0), 2) . "\n";
echo "  total_gain:          $" . number_format($m->get('total_market_gain_dollars', 0), 2) . "\n\n";

echo "=== Performance page computation (market_value field) ===\n";
$holdings = Holding::whereIn('portfolio_id', $portfolios->pluck('id'))
    ->where('quantity', '>', 0)
    ->with('market_data')
    ->get();

$bySymbol = $holdings->groupBy('symbol');
$computedMV = 0;
$computedMVBase = 0;
foreach ($bySymbol as $symbol => $group) {
    $qty = $group->sum('quantity');
    $mv = $group->first()->market_data->market_value ?? 0;
    $mvBase = $group->first()->market_data->market_value_base ?? 0;
    $computedMV += $mv * $qty;
    $computedMVBase += $mvBase * $qty;
}
echo "  Using market_value:       $" . number_format($computedMV, 2) . "\n";
echo "  Using market_value_base:  $" . number_format($computedMVBase, 2) . "\n\n";

echo "=== Dashboard logic ===\n";
$calculated = $m->get('total_market_value', 0);
$diff = abs($brokerTotal - $calculated);
$threshold = $brokerTotal * 0.1;
echo "  Calculated:  $" . number_format($calculated, 2) . "\n";
echo "  Broker:      $" . number_format($brokerTotal, 2) . "\n";
echo "  Diff:        $" . number_format($diff, 2) . " (threshold: $" . number_format($threshold, 2) . ")\n";
if ($brokerTotal > 0 && $diff > $threshold) {
    echo "  → USING BROKER VALUE (diff > 10%)\n";
} else {
    echo "  → Using calculated value\n";
}
