<?php

namespace App\Services\Reports;

use App\Contracts\ReportColumnDataProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Legacy implementation: Uses companies.exclude_from_consolidated_report
 *
 * This service encapsulates the current logic for discriminating companies
 * in consolidated reports. It will be replaced by ReportGrouperColumnProvider
 * in the future, but is kept for backward compatibility and gradual migration.
 *
 * @deprecated Future versions will use ReportGrouperColumnProvider
 */
class DiscriminatedCompanyColumnProvider implements ReportColumnDataProviderInterface
{
    /**
     * Get column data for discriminated companies
     *
     * {@inheritDoc}
     */
    public function getColumnData(array $advanceOrderIds): Collection
    {
        // Get unique order lines from discriminated companies
        // (avoid counting duplicates from overlapping OPs)
        $uniqueOrderLines = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds)
            ->where('companies.exclude_from_consolidated_report', true)
            ->select(
                'orders.id as order_id',
                'order_lines.product_id',
                'companies.id as company_id',
                'companies.name as company_name',
                'companies.fantasy_name as company_fantasy_name',
                'order_lines.quantity'
            )
            ->distinct() // Get unique combinations of order + product + company
            ->get();

        // Group by product and company
        return $uniqueOrderLines->groupBy('product_id')
            ->map(function ($productOrders) {
                return $productOrders->groupBy('company_id')
                    ->map(function ($companyOrders) {
                        $first = $companyOrders->first();

                        // Build display name (prefer fantasy_name over name)
                        $displayName = !empty($first->company_fantasy_name)
                            ? $first->company_fantasy_name
                            : $first->company_name;

                        return (object) [
                            'column_id' => $first->company_id,
                            'column_name' => $first->company_name,
                            'display_name' => $displayName,
                            'display_order' => $first->company_id, // Use company_id as order
                            'total_quantity' => $companyOrders->sum('quantity'),
                            'metadata' => [
                                'company_id' => $first->company_id,
                                'company_fantasy_name' => $first->company_fantasy_name,
                            ]
                        ];
                    })->values();
            });
    }

    /**
     * Get column headers for discriminated companies
     *
     * {@inheritDoc}
     */
    public function getColumnHeaders(array $advanceOrderIds): array
    {
        $columnData = $this->getColumnData($advanceOrderIds);
        $headers = [];

        // Extract unique headers from all products
        foreach ($columnData as $productId => $columns) {
            foreach ($columns as $column) {
                $columnKey = $this->getColumnKeyPrefix() . $column->column_id;
                if (!isset($headers[$columnKey])) {
                    $headers[$columnKey] = $column->display_name;
                }
            }
        }

        return $headers;
    }

    /**
     * Get column key prefix for companies
     *
     * {@inheritDoc}
     */
    public function getColumnKeyPrefix(): string
    {
        return 'company_';
    }
}
