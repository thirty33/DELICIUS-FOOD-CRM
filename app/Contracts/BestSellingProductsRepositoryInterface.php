<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface BestSellingProductsRepositoryInterface
{
    /**
     * Get the best-selling products for a specific role within a date range.
     *
     * @param string $roleName The role name to filter users
     * @param string $startDate Start date for the query (inclusive)
     * @param string $endDate End date for the query (inclusive)
     * @param int $limit Number of top products to return
     * @return Collection Collection of product data with sales statistics
     */
    public function getBestSellingProductsByRole(
        string $roleName,
        string $startDate,
        string $endDate,
        int $limit = 10
    ): Collection;

    /**
     * Get the best-selling product IDs for a specific role within a date range.
     *
     * @param string $roleName The role name to filter users
     * @param string $startDate Start date for the query (inclusive)
     * @param string $endDate End date for the query (inclusive)
     * @param int $limit Number of top products to return
     * @return array Array of product IDs ordered by sales volume
     */
    public function getBestSellingProductIdsByRole(
        string $roleName,
        string $startDate,
        string $endDate,
        int $limit = 10
    ): array;
}
