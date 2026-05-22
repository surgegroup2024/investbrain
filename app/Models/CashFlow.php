<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashFlow extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'portfolio_id',
        'type',
        'amount',
        'currency',
        'date',
        'description',
        'external_id',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function scopeDeposits($query)
    {
        return $query->where('type', 'DEPOSIT');
    }

    public function scopeWithdrawals($query)
    {
        return $query->where('type', 'WITHDRAWAL');
    }
}
