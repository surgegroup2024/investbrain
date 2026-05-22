<?php

declare(strict_types=1);

namespace App\Http\ApiControllers;

use App\Http\ApiControllers\Controller as ApiController;
use App\Models\OptionActivity;
use App\Models\Portfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OptionActivityController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = OptionActivity::query()
            ->whereHas('portfolio', function ($q) {
                $q->myPortfolios();
            })
            ->orderBy('date', 'desc');

        if ($request->has('portfolio_id')) {
            $query->where('portfolio_id', $request->input('portfolio_id'));
        }

        if ($request->has('symbol')) {
            $query->where('symbol', $request->input('symbol'));
        }

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'portfolio_id' => 'required|uuid|exists:portfolios,id',
            'symbol' => 'required|string|max:10',
            'action' => 'required|in:SELL_TO_OPEN,BUY_TO_CLOSE,BUY_TO_OPEN,SELL_TO_CLOSE',
            'option_type' => 'required|in:CALL,PUT',
            'contracts' => 'required|integer|min:1',
            'strike_price' => 'required|numeric|min:0',
            'expiration_date' => 'required|date',
            'premium_per_share' => 'required|numeric|min:0',
            'total_premium' => 'required|numeric',
            'currency' => 'sometimes|string|max:10',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255|unique:option_activities,external_id',
        ]);

        $portfolio = Portfolio::findOrFail($validated['portfolio_id']);
        Gate::authorize('fullAccess', $portfolio);

        $activity = OptionActivity::create($validated);

        return response()->json($activity, 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $query = OptionActivity::query()
            ->whereHas('portfolio', function ($q) {
                $q->myPortfolios();
            });

        if ($request->has('portfolio_id')) {
            $query->where('portfolio_id', $request->input('portfolio_id'));
        }

        $premiumReceived = (float) (clone $query)->premiumReceived()->sum('total_premium');
        $premiumPaid = abs((float) (clone $query)->premiumPaid()->sum('total_premium'));
        $netPremium = $premiumReceived - $premiumPaid;

        // Per-symbol breakdown
        $bySymbol = (clone $query)->get()->groupBy('symbol')->map(function ($activities, $symbol) {
            $received = $activities->filter(fn ($a) => in_array($a->action, ['SELL_TO_OPEN', 'SELL_TO_CLOSE']))->sum('total_premium');
            $paid = abs($activities->filter(fn ($a) => in_array($a->action, ['BUY_TO_CLOSE', 'BUY_TO_OPEN']))->sum('total_premium'));
            return [
                'symbol' => $symbol,
                'premium_received' => round($received, 2),
                'premium_paid' => round($paid, 2),
                'net_premium' => round($received - $paid, 2),
                'trades' => $activities->count(),
            ];
        })->sortByDesc('net_premium')->values();

        return response()->json([
            'total_premium_received' => round($premiumReceived, 2),
            'total_premium_paid' => round($premiumPaid, 2),
            'net_premium_income' => round($netPremium, 2),
            'by_symbol' => $bySymbol,
        ]);
    }
}
