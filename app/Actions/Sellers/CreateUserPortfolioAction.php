<?php

namespace App\Actions\Sellers;

use App\Actions\Contracts\CreateAction;
use App\Models\UserPortfolio;

final class CreateUserPortfolioAction implements CreateAction
{
    public static function execute(array $data = []): UserPortfolio
    {
        return UserPortfolio::create([
            'user_id' => data_get($data, 'user_id'),
            'portfolio_id' => data_get($data, 'portfolio_id'),
            'is_active' => true,
            'assigned_at' => now(),
            'branch_created_at' => data_get($data, 'branch_created_at'),
            'previous_portfolio_id' => data_get($data, 'previous_portfolio_id'),
            'first_order_at' => data_get($data, 'first_order_at'),
            'month_closed_at' => data_get($data, 'month_closed_at'),
        ]);
    }
}
