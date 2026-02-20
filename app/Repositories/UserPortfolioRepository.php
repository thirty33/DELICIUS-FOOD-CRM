<?php

namespace App\Repositories;

use App\Models\UserPortfolio;
use Illuminate\Support\Collection;

class UserPortfolioRepository
{
    /**
     * Return all active user_portfolio records whose month_closed_at has already passed.
     *
     * Eager-loads portfolio.successorPortfolio so callers can check for a successor
     * without triggering additional queries.
     */
    public function getActiveWithExpiredCycle(): Collection
    {
        return UserPortfolio::query()
            ->where('is_active', true)
            ->whereNotNull('month_closed_at')
            ->where('month_closed_at', '<=', now())
            ->with(['portfolio.successorPortfolio'])
            ->get();
    }
}
