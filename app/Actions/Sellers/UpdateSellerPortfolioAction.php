<?php

namespace App\Actions\Sellers;

use App\Actions\Contracts\UpdateAction;
use App\Models\SellerPortfolio;

final class UpdateSellerPortfolioAction implements UpdateAction
{
    public static function execute(array $data = []): SellerPortfolio
    {
        $portfolio = SellerPortfolio::findOrFail(data_get($data, 'id'));

        $portfolio->update([
            'name' => data_get($data, 'name'),
            'category' => data_get($data, 'category'),
            'seller_id' => data_get($data, 'seller_id'),
            'successor_portfolio_id' => data_get($data, 'successor_portfolio_id'),
            'is_default' => data_get($data, 'is_default', false),
        ]);

        return $portfolio;
    }
}
