<?php

declare(strict_types=1);

namespace App\Http\ApiControllers;

use App\Models\Holding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingReconcileController extends Controller
{
    /**
     * Return simple symbol+quantity for a portfolio (no joins/grouping).
     * Used by the sync agent for holdings reconciliation.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'portfolio_id' => ['required', 'uuid'],
        ]);

        $holdings = Holding::where('portfolio_id', $request->portfolio_id)
            ->where('quantity', '>', 0)
            ->select(['symbol', 'quantity', 'average_cost_basis'])
            ->get();

        return response()->json(['data' => $holdings]);
    }
}
