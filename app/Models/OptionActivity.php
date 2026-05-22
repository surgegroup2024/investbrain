<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionActivity extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'portfolio_id',
        'symbol',
        'action',
        'option_type',
        'contracts',
        'strike_price',
        'expiration_date',
        'premium_per_share',
        'total_premium',
        'currency',
        'date',
        'description',
        'external_id',
    ];

    protected $casts = [
        'date' => 'date',
        'expiration_date' => 'date',
        'strike_price' => 'float',
        'premium_per_share' => 'float',
        'total_premium' => 'float',
        'contracts' => 'integer',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function scopeOpens($query)
    {
        return $query->whereIn('action', ['SELL_TO_OPEN', 'BUY_TO_OPEN']);
    }

    public function scopeCloses($query)
    {
        return $query->whereIn('action', ['BUY_TO_CLOSE', 'SELL_TO_CLOSE']);
    }

    public function scopePremiumReceived($query)
    {
        return $query->whereIn('action', ['SELL_TO_OPEN', 'SELL_TO_CLOSE']);
    }

    public function scopePremiumPaid($query)
    {
        return $query->whereIn('action', ['BUY_TO_CLOSE', 'BUY_TO_OPEN']);
    }
}
