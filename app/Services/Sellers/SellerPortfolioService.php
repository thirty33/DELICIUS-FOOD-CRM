<?php

namespace App\Services\Sellers;

use App\Repositories\SellerPortfolioRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Collection;

class SellerPortfolioService
{
    public function __construct(
        private SellerPortfolioRepository $portfolioRepository,
        private UserRepository $userRepository,
    ) {}

    public function getActiveClientsForPortfolio(int $portfolioId): Collection
    {
        return $this->portfolioRepository->getActiveClientsForPortfolio($portfolioId);
    }

    public function getClientPortfolioHistory(int $userId): Collection
    {
        return $this->portfolioRepository->getClientPortfolioHistory($userId);
    }

    public function getPortfoliosForSelect(?int $excludeId = null): Collection
    {
        return $this->portfolioRepository->getPortfoliosForSelect($excludeId);
    }

    public function getSellersForSelect(): Collection
    {
        return $this->userRepository->getSellers();
    }
}
