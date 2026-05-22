<?php

declare(strict_types=1);

use App\Http\ApiControllers\CashFlowController;
use App\Http\ApiControllers\HoldingController;
use App\Http\ApiControllers\HoldingReconcileController;
use App\Http\ApiControllers\MarketDataController;
use App\Http\ApiControllers\OptionActivityController;
use App\Http\ApiControllers\PortfolioController;
use App\Http\ApiControllers\TransactionController;
use App\Http\ApiControllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->name('api.')->group(function () {

    // user
    Route::get('/me', [UserController::class, 'me'])->name('me');

    // portfolio
    Route::apiResource('/portfolio', PortfolioController::class);

    // transaction
    Route::apiResource('/transaction', TransactionController::class);

    // holding
    Route::get('/holding', [HoldingController::class, 'index'])->name('holding.index');
    Route::get('/holding/reconcile', [HoldingReconcileController::class, 'index'])->name('holding.reconcile');
    Route::get('/holding/{portfolio}/{symbol}', [HoldingController::class, 'show'])->name('holding.show')->scopeBindings();
    Route::put('/holding/{portfolio}/{symbol}', [HoldingController::class, 'update'])->name('holding.update')->scopeBindings();

    // market data
    Route::get('/market-data/{symbol}', [MarketDataController::class, 'show'])->name('market-data.show');

    // cash flows (deposits/withdrawals)
    Route::get('/cash-flow', [CashFlowController::class, 'index'])->name('cash-flow.index');
    Route::post('/cash-flow', [CashFlowController::class, 'store'])->name('cash-flow.store');
    Route::get('/cash-flow/summary', [CashFlowController::class, 'summary'])->name('cash-flow.summary');
    Route::delete('/cash-flow/{cashFlow}', [CashFlowController::class, 'destroy'])->name('cash-flow.destroy');

    // options activity (premium tracking)
    Route::get('/options-activity', [OptionActivityController::class, 'index'])->name('options-activity.index');
    Route::post('/options-activity', [OptionActivityController::class, 'store'])->name('options-activity.store');
    Route::get('/options-activity/summary', [OptionActivityController::class, 'summary'])->name('options-activity.summary');
});
