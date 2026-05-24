<?php

declare(strict_types=1);

namespace App\Models;

use App\Interfaces\MarketData\MarketDataInterface;
use App\Models\ChatWithConversation;
use App\Notifications\InvitedOnboardingNotification;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Portfolio extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'title',
        'notes',
        'wishlist',
        'performance_start_date',
        'account_type',
        'broker_value',
        'broker_value_updated_at',
    ];

    public static ?string $owner_id = null;

    protected static function boot()
    {
        parent::boot();

        static::saved(function ($portfolio) {

            self::ensurePortfolioHasOwner($portfolio);
        });
    }

    protected $hidden = [];

    protected $casts = [
        'wishlist' => 'boolean',
        'performance_start_date' => 'date',
        'broker_value' => 'float',
        'broker_value_updated_at' => 'datetime',
    ];

    protected $with = ['users', 'transactions'];

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot(['owner', 'full_access', 'invite_accepted_at']);
    }

    public function holdings()
    {
        return $this->hasMany(Holding::class, 'portfolio_id')
            ->withMarketData()
            ->withPerformance();
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class)->orderBy('created_at', 'DESC');
    }

    public function daily_change()
    {
        return $this->hasMany(DailyChange::class);
    }

    public function cashFlows()
    {
        return $this->hasMany(CashFlow::class)->orderBy('date', 'DESC');
    }

    public function optionActivities()
    {
        return $this->hasMany(OptionActivity::class)->orderBy('date', 'DESC');
    }

    public function chatWithConversation(): \Illuminate\Database\Eloquent\Relations\MorphOne
    {
        return $this->morphOne(ChatWithConversation::class, 'chatable')
            ->where('user_id', auth()->id());
    }

    public function scopeMyPortfolios()
    {
        return $this->whereHas('users', function ($query) {
            $query->where('user_id', auth()->user()->id);
        });
    }

    public function scopeFullAccess($query, $user_id = null)
    {
        return $query->whereHas('users', function ($query) use ($user_id) {
            $query->where('user_id', $user_id ?? auth()->user()->id)
                ->where(function ($query) {
                    $query->where('full_access', true)
                        ->orWhere('owner', true);
                });
        });
    }

    public function scopeWithoutWishlists()
    {
        return $this->where(['wishlist' => false]);
    }

    public function setOwnerIdAttribute($value)
    {
        // enable queued jobs to create portfolios with owners
        if (! auth()->user()?->id && ! $this->owner_id) {
            static::$owner_id = $value;
        }
    }

    public function getOwnerIdAttribute()
    {
        return $this->owner?->id;
    }

    public function getOwnerAttribute()
    {
        if (! $this->relationLoaded('user')) {

            $this->load('users');
        }

        return $this->users->where('pivot.owner', true)->first();
    }

    public static function ensurePortfolioHasOwner(self $portfolio)
    {
        // make sure we don't remove owner access
        if (! $portfolio->owner_id) {
            $owner[static::$owner_id ?? auth()->user()->id] = ['owner' => true];

            // save
            $portfolio->users()->sync($owner);
            static::$owner_id = null;
        }
    }

    /**
     * Writes daily change history for a portfolio to the database
     */
    public function syncDailyChanges(): void
    {
        $holdings = $this->holdings()
            ->join('transactions', function ($join) {
                $join->on('transactions.symbol', '=', 'holdings.symbol')
                    ->where('transactions.portfolio_id', '=', $this->id);
            })
            ->select('holdings.symbol', 'holdings.portfolio_id', DB::raw('min(transactions.date) as first_transaction_date')) // get first transaction date
            ->groupBy(['holdings.symbol', 'holdings.portfolio_id'])
            ->get();

        $total_performance = [];

        // get unique currencies for holdings
        $currency_rates = [];
        foreach ($holdings->groupBy('market_data.currency')->keys() as $currency) {
            $currency_rates[$currency] = CurrencyRate::timeSeriesRates($currency, $holdings->min('first_transaction_date'), now());
        }

        $holdings->each(function ($holding) use (&$total_performance, $currency_rates) {

            $period = CarbonPeriod::create(
                $holding->first_transaction_date,
                now()->isBefore(Carbon::parse(config('investbrain.daily_change_time_of_day')))
                    ? now()->subDay()
                    : now()
            );

            $daily_performance = $holding->dailyPerformance($holding->first_transaction_date, now());
            $all_history = app(MarketDataInterface::class)->history($holding->symbol, $holding->first_transaction_date, now());

            $holding_performance = [];

            foreach ($period as $date) {
                $date = $date->toDateString();

                $close = $this->getMostRecentCloseData($all_history, $date);

                $total_market_value = $daily_performance->get($date)->owned * $close;

                if (Carbon::parse($date)->isWeekday()) {

                    $holding_performance[$date] = [
                        'date' => $date,
                        'portfolio_id' => $this->id,
                        'total_market_value' => $total_market_value * (1 / Arr::get($currency_rates[$holding->market_data->currency], $date, 1)),
                    ];
                }
            }

            foreach ($holding_performance as $date => $performance) {
                if (Arr::get($total_performance, $date) == null) {

                    $total_performance[$date] = $performance;

                } else {

                    $total_performance[$date]['total_market_value'] += $performance['total_market_value'];
                }
            }
        });

        if (! empty($total_performance)) {
            DB::transaction(function () use ($total_performance) {

                // delete old history
                $firstDate = array_keys($total_performance)[0];
                $this->daily_change()->where('date', '<', $firstDate)->delete();

                // upsert new history
                $this->daily_change()->upsert(
                    $total_performance,
                    ['date', 'portfolio_id'],
                    [
                        'total_market_value',
                    ]
                );
            });
        }

        cache()->forget('graph-YTD-'.$this->id);
        cache()->forget('graph-YTD-'.request()->user()?->id);
    }

    protected function getMostRecentCloseData($history, $date, $i = 0, $max_attempts = 5)
    {
        $close = Arr::get($history, "$date.close", 0);

        if (! $close && $i < $max_attempts) {

            $i++;

            $date = Carbon::parse($date)->subDay()->toDateString();

            return $this->getMostRecentCloseData($history, $date, $i);
        }

        return $close;
    }

    /**
     * Calculate capital metrics: total deposits, withdrawals, net invested, ROI
     */
    public function capitalMetrics(): array
    {
        $deposits = (float) $this->cashFlows()->deposits()->sum('amount');
        $withdrawals = abs((float) $this->cashFlows()->withdrawals()->sum('amount'));
        $netInvested = $deposits - $withdrawals;

        $holdings = $this->holdings()->get();
        $calculatedValue = (float) $holdings->sum('total_market_value');
        $marketValue = $this->getEffectiveMarketValue($calculatedValue);
        $roi = $netInvested > 0 ? (($marketValue + $withdrawals - $deposits) / $deposits) * 100 : 0;

        // Money-weighted return (simple IRR approximation using modified Dietz)
        $irr = $this->calculateModifiedDietz();

        // Determine history length for annualized display
        // Use performance_start_date override if set, otherwise use first cash flow date
        if ($this->performance_start_date) {
            $yearsOfHistory = $this->performance_start_date->diffInDays(now()) / 365.25;
        } else {
            $firstFlow = $this->cashFlows()->reorder('date', 'asc')->first();
            $yearsOfHistory = $firstFlow ? $firstFlow->date->diffInDays(now()) / 365.25 : 0;
        }

        return [
            'total_deposits' => round($deposits, 2),
            'total_withdrawals' => round($withdrawals, 2),
            'net_invested' => round($netInvested, 2),
            'current_value' => round($marketValue, 2),
            'total_return_dollars' => round($marketValue + $withdrawals - $deposits, 2),
            'total_return_pct' => round($roi, 2),
            'modified_dietz_return' => round($irr, 2),
            'years_of_history' => round($yearsOfHistory, 1),
        ];
    }

    /**
     * Get effective market value, preferring broker_value when it significantly differs from calculated.
     */
    protected function getEffectiveMarketValue(float $calculatedValue): float
    {
        if ($this->broker_value && abs($this->broker_value - $calculatedValue) > $this->broker_value * 0.1) {
            return (float) $this->broker_value;
        }

        return $calculatedValue;
    }

    /**
     * Modified Dietz method for money-weighted return
     */
    protected function calculateModifiedDietz(): float
    {
        $flows = $this->cashFlows()->reorder('date', 'asc')->get();
        if ($flows->isEmpty()) {
            return 0.0;
        }

        $startDate = $flows->first()->date;
        $endDate = Carbon::now();
        $totalDays = (float) $startDate->diffInDays($endDate);

        if ($totalDays <= 0) {
            return 0.0;
        }

        $startValue = 0; // assume portfolio started at 0
        $calculatedEnd = (float) $this->holdings()->get()->sum('total_market_value');
        $endValue = $this->getEffectiveMarketValue($calculatedEnd);

        $weightedFlows = 0;
        $totalFlows = 0;

        foreach ($flows as $flow) {
            $amount = $flow->type === 'DEPOSIT' ? (float) $flow->amount : -abs((float) $flow->amount);
            $daysRemaining = (float) $flow->date->diffInDays($endDate);
            $weight = $daysRemaining / $totalDays;
            $weightedFlows += $amount * $weight;
            $totalFlows += $amount;
        }

        $denominator = $startValue + $weightedFlows;
        if (abs($denominator) < 0.01) {
            return 0.0;
        }

        return (($endValue - $startValue - $totalFlows) / $denominator) * 100;
    }

    public function getFormattedHoldings()
    {
        $formattedHoldings = '';
        foreach ($this->holdings as $holding) {
            $formattedHoldings .= ' * Holding of '.$holding->market_data->name.' ('.$holding->symbol.')'
                                    .'; with '.($holding->quantity > 0 ? $holding->quantity : 'ZERO').' shares'
                                    .'; avg cost basis '.$holding->average_cost_basis
                                    .'; curr market value '.$holding->market_data->market_value
                                    .'; unrealized gains '.$holding->market_gain_dollars
                                    .'; realized gains '.$holding->realized_gain_dollars
                                    .'; dividends earned '.$holding->dividends_earned
                                    ."\n\n";
        }

        return $formattedHoldings;
    }

    /**
     * Share a portfolio with a user
     */
    public function share(string $email, bool $fullAccess = false): void
    {
        $user = User::firstOrCreate([
            'email' => $email,
        ], [
            'name' => Str::title(Str::before($email, '@')),
        ]);

        $permissions[$user->id] = [
            'full_access' => $fullAccess,
        ];

        $sync = $this->users()->syncWithoutDetaching($permissions);

        if (! empty($sync['attached'])) {

            foreach ($sync['attached'] as $newUserId) {
                User::find($newUserId)->notify(new InvitedOnboardingNotification($this, auth()->user()));
            }
        }
    }

    /**
     * Un-share a portfolio
     */
    public function unShare(string $userId): void
    {
        $this->users()->detach($userId);
    }
}
