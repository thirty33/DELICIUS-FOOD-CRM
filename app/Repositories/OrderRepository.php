<?php

namespace App\Repositories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class OrderRepository
{
    /**
     * Filter orders by roles, permissions, branches, and statuses
     *
     * @param Collection $orders Collection of Order models or IDs
     * @param array $filters Array with keys: user_roles, agreement_types, branch_ids, order_statuses
     * @return array Filtered order IDs
     */
    public function filterOrdersByRolesAndStatuses(Collection $orders, array $filters): array
    {
        // Get order IDs from collection
        $orderIds = $orders->pluck('id')->toArray();

        // Start building query
        $query = Order::whereIn('id', $orderIds)
            ->whereIn('status', $filters['order_statuses'] ?? []);

        // Apply user role filter if provided
        if (!empty($filters['user_roles'])) {
            $query->whereHas('user.roles', function (Builder $q) use ($filters) {
                $q->whereIn('name', $filters['user_roles']);
            });
        }

        // Apply agreement type (permission) filter if provided
        if (!empty($filters['agreement_types'])) {
            $query->whereHas('user.permissions', function (Builder $q) use ($filters) {
                $q->whereIn('name', $filters['agreement_types']);
            });
        }

        // Apply branch filter if provided
        if (!empty($filters['branch_ids'])) {
            $query->whereHas('user.branch', function (Builder $q) use ($filters) {
                $q->whereIn('branches.id', $filters['branch_ids']);
            });
        }

        return $query->pluck('id')->toArray();
    }

    /**
     * Check if filtered result is empty
     *
     * @param array $filteredOrderIds
     * @return bool
     */
    public function hasFilteredOrders(array $filteredOrderIds): bool
    {
        return !empty($filteredOrderIds);
    }

    /**
     * Get unique products with quantities from orders within a date range
     *
     * Only includes products from:
     * - PROCESSED orders (all order lines)
     * - PARTIALLY_SCHEDULED orders (only order lines with partially_scheduled = true)
     *
     * Excludes:
     * - PENDING orders
     * - CANCELED orders
     * - Order lines with partially_scheduled = false from PARTIALLY_SCHEDULED orders
     *
     * @param string $startDate
     * @param string $endDate
     * @return Collection Collection of arrays with 'product_id' and 'ordered_quantity'
     */
    public function getProductsFromOrdersInDateRange(string $startDate, string $endDate): Collection
    {
        $orders = Order::whereBetween('dispatch_date', [$startDate, $endDate])
            ->whereIn('status', [\App\Enums\OrderStatus::PROCESSED->value, \App\Enums\OrderStatus::PARTIALLY_SCHEDULED->value])
            ->with('orderLines.product')
            ->get();

        $filteredOrderLines = collect();

        foreach ($orders as $order) {
            foreach ($order->orderLines as $orderLine) {
                // Include all order lines from PROCESSED orders
                if ($order->status === \App\Enums\OrderStatus::PROCESSED->value) {
                    $filteredOrderLines->push($orderLine);
                    continue;
                }

                // For PARTIALLY_SCHEDULED orders, only include lines with partially_scheduled = true
                if ($order->status === \App\Enums\OrderStatus::PARTIALLY_SCHEDULED->value && $orderLine->partially_scheduled) {
                    $filteredOrderLines->push($orderLine);
                }
            }
        }

        return $filteredOrderLines
            ->groupBy('product_id')
            ->map(function ($orderLines, $productId) {
                return [
                    'product_id' => $productId,
                    'ordered_quantity' => $orderLines->sum('quantity'),
                ];
            })
            ->values();
    }
}
