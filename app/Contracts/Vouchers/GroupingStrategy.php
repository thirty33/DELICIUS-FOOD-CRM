<?php

namespace App\Contracts\Vouchers;

use Illuminate\Support\Collection;

/**
 * Interface for different voucher grouping strategies
 *
 * Implements Strategy Pattern to allow different ways of grouping orders
 * for voucher generation
 */
interface GroupingStrategy
{
    /**
     * Group orders according to specific criteria
     *
     * @param Collection $orders Collection of Order models
     * @return array Array of grouped orders (each group is an array of orders)
     */
    public function group(Collection $orders): array;

    /**
     * Get the title/identifier for a specific group
     *
     * @param array $orderGroup Array of orders in the same group
     * @return string Group identifier/title
     */
    public function getGroupTitle(array $orderGroup): string;
}
