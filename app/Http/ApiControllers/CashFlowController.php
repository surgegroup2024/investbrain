<?php

declare(strict_types=1);

namespace App\Http\ApiControllers;

use App\Http\ApiControllers\Controller as ApiController;
use App\Models\CashFlow;
use App\Models\Portfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CashFlowController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = CashFlow::query()
            ->whereHas('portfolio', function ($q) {
                $q->myPortfolios();
            })
            ->orderBy('date', 'desc');

        if ($request->has('portfolio_id')) {
            $query->where('portfolio_id', $request->input('portfolio_id'));
        }

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'portfolio_id' => 'required|uuid|exists:portfolios,id',
            'type' => 'required|in:DEPOSIT,WITHDRAWAL',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|max:10',
            'date' => 'required|date',
            'description' => 'nullable|string|max:255',
            'external_id' => 'nullable|string|max:255|unique:cash_flows,external_id',
        ]);

        $portfolio = Portfolio::findOrFail($validated['portfolio_id']);
        Gate::authorize('fullAccess', $portfolio);

        $cashFlow = CashFlow::create($validated);

        return response()->json($cashFlow, 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $portfolioId = $request->input('portfolio_id');

        $query = CashFlow::query()
            ->whereHas('portfolio', function ($q) {
                $q->myPortfolios();
            });

        if ($portfolioId) {
            $query->where('portfolio_id', $portfolioId);
        }

        $deposits = (clone $query)->deposits()->sum('amount');
        $withdrawals = abs((float) (clone $query)->withdrawals()->sum('amount'));

        // If portfolio-specific, get capital metrics
        $metrics = null;
        if ($portfolioId) {
            $portfolio = Portfolio::with('holdings')->findOrFail($portfolioId);
            $metrics = $portfolio->capitalMetrics();
        }

        return response()->json([
            'total_deposits' => round((float) $deposits, 2),
            'total_withdrawals' => round($withdrawals, 2),
            'net_invested' => round((float) $deposits - $withdrawals, 2),
            'metrics' => $metrics,
        ]);
    }

    public function destroy(CashFlow $cashFlow): JsonResponse
    {
        Gate::authorize('fullAccess', $cashFlow->portfolio);
        $cashFlow->delete();

        return response()->json(null, 204);
    }
}
