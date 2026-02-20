<?php

namespace App\Actions\Sellers;

use App\Actions\Contracts\UpdateAction;
use App\Models\UserPortfolio;

final class CloseUserPortfolioAction implements UpdateAction
{
    public static function execute(array $data = []): UserPortfolio
    {
        $userPortfolio = UserPortfolio::findOrFail(data_get($data, 'user_portfolio_id'));

        $userPortfolio->update(['is_active' => false]);

        return $userPortfolio;
    }
}
