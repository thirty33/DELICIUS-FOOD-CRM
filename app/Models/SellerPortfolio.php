<?php

namespace App\Models;

use App\Enums\PortfolioCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerPortfolio extends Model
{
    protected $fillable = [
        'name',
        'category',
        'seller_id',
        'successor_portfolio_id',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'category' => PortfolioCategory::class,
            'is_default' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function successorPortfolio(): BelongsTo
    {
        return $this->belongsTo(SellerPortfolio::class, 'successor_portfolio_id');
    }

    public function userPortfolios(): HasMany
    {
        return $this->hasMany(UserPortfolio::class, 'portfolio_id');
    }

    public function activeUserPortfolios(): HasMany
    {
        return $this->hasMany(UserPortfolio::class, 'portfolio_id')->where('is_active', true);
    }
}
