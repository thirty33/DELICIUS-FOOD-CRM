<?php

namespace App\Repositories;

use App\Enums\PortfolioCategory;
use App\Models\SellerPortfolio;
use App\Models\UserPortfolio;
use Illuminate\Support\Collection;

class SellerPortfolioRepository
{
    public function getActiveClientsForPortfolio(int $portfolioId): Collection
    {
        return UserPortfolio::query()
            ->where('portfolio_id', $portfolioId)
            ->where('is_active', true)
            ->with(['user.company'])
            ->get();
    }

    public function getClientPortfolioHistory(int $userId): Collection
    {
        return UserPortfolio::query()
            ->where('user_id', $userId)
            ->with(['portfolio.seller', 'previousPortfolio'])
            ->orderByDesc('assigned_at')
            ->get();
    }

    public function getPortfoliosForSelect(?int $excludeId = null): Collection
    {
        return SellerPortfolio::query()
            ->with('seller')
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get();
    }

    public function getDefaultByCategory(PortfolioCategory $category): ?SellerPortfolio
    {
        return SellerPortfolio::query()
            ->where('category', $category->value)
            ->where('is_default', true)
            ->first();
    }
}
