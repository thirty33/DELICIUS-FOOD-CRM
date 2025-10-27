<?php

namespace App\Services\Vouchers\Strategies;

use App\Contracts\Vouchers\GroupingStrategy;
use Illuminate\Support\Collection;

/**
 * Company Grouping Strategy
 *
 * Groups orders by company_id only
 * All orders from the same company will be consolidated into a single voucher
 */
class CompanyDispatchDateGrouping implements GroupingStrategy
{
    /**
     * Group orders by company_id only
     *
     * @param Collection $orders
     * @return array Array of grouped orders
     */
    public function group(Collection $orders): array
    {
        return $orders->groupBy(function ($order) {
            return $order->user->company_id;
        })->map(function ($group) {
            return $group->all(); // Convert Collection to array
        })->values()->toArray();
    }

    /**
     * Get title for a consolidated group
     *
     * Format: "Pedidos N° 202, 205, 206, 207"
     *
     * @param array $orderGroup Array of orders in the same group
     * @return string Group title with all order IDs separated by comma
     */
    public function getGroupTitle(array $orderGroup): string
    {
        if (empty($orderGroup)) {
            return 'N/A';
        }

        // Get all order IDs
        $orderIds = array_map(fn($order) => $order->id, $orderGroup);
        sort($orderIds);

        if (count($orderIds) === 1) {
            return "Pedido N° {$orderIds[0]}";
        }

        $idsString = implode(', ', $orderIds);
        return "Pedidos N° {$idsString}";
    }

    /**
     * Get dispatch date range for the group
     *
     * @param array $orderGroup Array of orders in the same group
     * @return string Formatted date range (e.g., "28/10/2025 - 02/11/2025")
     */
    public function getDispatchDateRange(array $orderGroup): string
    {
        if (empty($orderGroup)) {
            return 'N/A';
        }

        // Get all unique dispatch dates
        $dates = array_unique(array_map(fn($order) => $order->dispatch_date, $orderGroup));

        if (count($dates) === 1) {
            return \Carbon\Carbon::parse($dates[0])->format('d/m/Y');
        }

        // Multiple dates - show range from earliest to latest
        sort($dates);
        $minDate = \Carbon\Carbon::parse($dates[0])->format('d/m/Y');
        $maxDate = \Carbon\Carbon::parse(end($dates))->format('d/m/Y');

        return "{$minDate} - {$maxDate}";
    }
}
