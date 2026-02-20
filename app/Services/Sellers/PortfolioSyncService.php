<?php

namespace App\Services\Sellers;

use App\Actions\Sellers\CloseUserPortfolioAction;
use App\Actions\Sellers\CreateUserPortfolioAction;
use App\Models\SellerPortfolio;
use App\Models\User;
use App\Models\UserPortfolio;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use Carbon\Carbon;

class PortfolioSyncService
{
    public function __construct(
        private UserRepository $userRepository,
        private OrderRepository $orderRepository,
    ) {}

    public function sync(): void
    {
        $clients = $this->userRepository->getClientsForPortfolioSync();

        foreach ($clients as $client) {
            $this->syncClient($client);
        }
    }

    private function syncClient(User $client): void
    {
        $sellerPortfolio = $client->seller->sellerPortfolios->first();
        $activeUserPortfolio = $client->activePortfolio;

        if ($activeUserPortfolio === null) {
            $this->handleNoActivePortfolio($client, $sellerPortfolio);

            return;
        }

        $this->handleExistingActivePortfolio($client, $activeUserPortfolio, $sellerPortfolio);
    }

    private function handleNoActivePortfolio(User $client, ?SellerPortfolio $sellerPortfolio): void
    {
        if ($sellerPortfolio === null) {
            return;
        }

        $oldestOrderDate = $this->orderRepository->getOldestOrderDateForUser($client->id);

        CreateUserPortfolioAction::execute([
            'user_id' => $client->id,
            'portfolio_id' => $sellerPortfolio->id,
            'branch_created_at' => $client->branch?->created_at,
            'first_order_at' => $oldestOrderDate,
            'month_closed_at' => $this->calculateMonthClosedAt($oldestOrderDate),
        ]);
    }

    private function handleExistingActivePortfolio(User $client, UserPortfolio $activeUserPortfolio, ?SellerPortfolio $sellerPortfolio): void
    {
        if ($activeUserPortfolio->portfolio->seller_id === $client->seller_id) {
            return;
        }

        if ($sellerPortfolio === null) {
            return;
        }

        $previousPortfolioId = $activeUserPortfolio->portfolio_id;

        CloseUserPortfolioAction::execute([
            'user_portfolio_id' => $activeUserPortfolio->id,
        ]);

        CreateUserPortfolioAction::execute([
            'user_id' => $client->id,
            'portfolio_id' => $sellerPortfolio->id,
            'branch_created_at' => $client->branch?->created_at,
            'previous_portfolio_id' => $previousPortfolioId,
        ]);
    }

    private function calculateMonthClosedAt(?Carbon $date): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        return $date->day === 1
            ? $date->copy()->endOfMonth()
            : $date->copy()->addMonthNoOverflow()->endOfMonth();
    }
}
