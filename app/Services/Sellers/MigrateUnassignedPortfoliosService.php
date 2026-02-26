<?php

namespace App\Services\Sellers;

use App\Actions\Sellers\CreateUserPortfolioAction;
use App\Actions\Sellers\UpdateUserSellerAction;
use App\Enums\PortfolioCategory;
use App\Models\SellerPortfolio;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\SellerPortfolioRepository;
use App\Repositories\UserRepository;
use Carbon\Carbon;

class MigrateUnassignedPortfoliosService
{
    private ?SellerPortfolio $ventaFresca = null;

    private ?SellerPortfolio $postVenta = null;

    public function __construct(
        private UserRepository $userRepository,
        private OrderRepository $orderRepository,
        private SellerPortfolioRepository $portfolioRepository,
    ) {}

    public function migrate(int $limit): array
    {
        $this->ventaFresca = $this->portfolioRepository->getDefaultByCategory(PortfolioCategory::VentaFresca);
        $this->postVenta = $this->portfolioRepository->getDefaultByCategory(PortfolioCategory::PostVenta);

        $clients = $this->userRepository->getUnassignedClientsForMigration($limit);

        $totals = ['venta_fresca' => 0, 'post_venta' => 0, 'skipped' => 0];

        foreach ($clients as $client) {
            $result = $this->migrateClient($client);
            $totals[$result]++;
        }

        return $totals;
    }

    private function migrateClient(User $client): string
    {
        $oldestOrderDate = $this->orderRepository->getOldestOrderDateForUser($client->id);
        $monthClosedAt = $this->calculateMonthClosedAt($oldestOrderDate);

        $isMonthClosed = $monthClosedAt !== null && $monthClosedAt->lte(now());

        if ($isMonthClosed) {
            return $this->assignToPostVenta($client, $oldestOrderDate);
        }

        return $this->assignToVentaFresca($client, $oldestOrderDate, $monthClosedAt);
    }

    private function assignToVentaFresca(User $client, ?Carbon $firstOrderAt, ?Carbon $monthClosedAt): string
    {
        if ($this->ventaFresca === null) {
            return 'skipped';
        }

        CreateUserPortfolioAction::execute([
            'user_id' => $client->id,
            'portfolio_id' => $this->ventaFresca->id,
            'branch_created_at' => $client->branch?->created_at,
            'first_order_at' => $firstOrderAt,
            'month_closed_at' => $monthClosedAt,
        ]);

        UpdateUserSellerAction::execute([
            'user_id' => $client->id,
            'seller_id' => $this->ventaFresca->seller_id,
        ]);

        return 'venta_fresca';
    }

    private function assignToPostVenta(User $client, ?Carbon $firstOrderAt): string
    {
        if ($this->postVenta === null) {
            return 'skipped';
        }

        CreateUserPortfolioAction::execute([
            'user_id' => $client->id,
            'portfolio_id' => $this->postVenta->id,
            'branch_created_at' => $client->branch?->created_at,
            'first_order_at' => $firstOrderAt,
        ]);

        UpdateUserSellerAction::execute([
            'user_id' => $client->id,
            'seller_id' => $this->postVenta->seller_id,
        ]);

        return 'post_venta';
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
