<?php

namespace App\Services\Vouchers\Strategies;

use App\Contracts\Vouchers\GroupingStrategy;
use Illuminate\Support\Collection;

/**
 * Single Order Grouping Strategy
 *
 * Groups each order individually - this is the current/default behavior
 * Each order generates its own separate voucher
 */
class SingleOrderGrouping implements GroupingStrategy
{
    /**
     * Group orders - each order is its own group
     *
     * @param Collection $orders
     * @return array Array where each element is an array containing a single order
     */
    public function group(Collection $orders): array
    {
        return $orders->map(function ($order) {
            return [$order]; // Each order is wrapped in its own array
        })->toArray();
    }

    /**
     * Get title for a single order group
     *
     * @param array $orderGroup Array containing a single order
     * @return string Order ID as title
     */
    public function getGroupTitle(array $orderGroup): string
    {
        if (empty($orderGroup)) {
            return 'N/A';
        }

        $order = $orderGroup[0];
        return "Pedido N° {$order->id}";
    }
}
