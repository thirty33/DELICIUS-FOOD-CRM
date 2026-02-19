<?php

namespace App\Actions\Sellers;

use App\Actions\Contracts\CreateAction;
use App\Models\SellerPortfolio;

final class CreateSellerPortfolioAction implements CreateAction
{
    public static function execute(array $data = []): SellerPortfolio
    {
        return SellerPortfolio::create([
            'name' => data_get($data, 'name'),
            'category' => data_get($data, 'category'),
            'seller_id' => data_get($data, 'seller_id'),
            'successor_portfolio_id' => data_get($data, 'successor_portfolio_id'),
            'is_default' => data_get($data, 'is_default', false),
        ]);
    }
}
