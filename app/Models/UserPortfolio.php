<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPortfolio extends Model
{
    protected $table = 'user_portfolio';

    protected $fillable = [
        'user_id',
        'portfolio_id',
        'is_active',
        'assigned_at',
        'branch_created_at',
        'first_order_at',
        'month_closed_at',
        'previous_portfolio_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'assigned_at' => 'datetime',
            'branch_created_at' => 'datetime',
            'first_order_at' => 'datetime',
            'month_closed_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(SellerPortfolio::class, 'portfolio_id');
    }

    public function previousPortfolio(): BelongsTo
    {
        return $this->belongsTo(SellerPortfolio::class, 'previous_portfolio_id');
    }
}
