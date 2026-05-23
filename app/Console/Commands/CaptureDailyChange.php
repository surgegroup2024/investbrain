<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Holding;
use App\Models\Portfolio;
use Illuminate\Console\Command;

class CaptureDailyChange extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'capture:daily-change';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Capture summary of daily change for user\'s holdings';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Portfolio::with('holdings.market_data')->get()->each(function ($portfolio) {

            $this->line('Capturing daily change for '.$portfolio->title);

            $metrics = Holding::query()
                ->portfolio($portfolio->id)
                ->getPortfolioMetrics(config('investbrain.base_currency'));

            $calculatedValue = $metrics->get('total_market_value');

            // Prefer broker-reported value when available and recent (includes options, cash, etc.)
            $marketValue = $calculatedValue;
            if ($portfolio->broker_value
                && $portfolio->broker_value_updated_at
                && $portfolio->broker_value_updated_at->greaterThan(now()->subHours(24))
            ) {
                $marketValue = $portfolio->broker_value;
            }

            $portfolio->daily_change()->create([
                'date' => now(),
                'total_market_value' => $marketValue,
            ]);
        });
    }
}
