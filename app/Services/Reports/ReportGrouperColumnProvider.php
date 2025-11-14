<?php

namespace App\Services\Reports;

use App\Contracts\ReportColumnDataProviderInterface;
use App\Models\ReportConfiguration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * New implementation: Uses report_groupers for discriminated columns
 *
 * This service provides column data based on the report_groupers configuration.
 * Companies are grouped using the company_report_grouper pivot table instead
 * of the companies.exclude_from_consolidated_report flag.
 *
 * Additionally, this service supports exclude_cafeterias and exclude_agreements
 * configuration flags to show CAFETERIAS and CONVENIOS columns for companies
 * that are NOT included in any grouper.
 */
class ReportGrouperColumnProvider implements ReportColumnDataProviderInterface
{
    /**
     * Get column data for groupers and exclusion columns
     *
     * {@inheritDoc}
     */
    public function getColumnData(array $advanceOrderIds): Collection
    {
        $config = ReportConfiguration::getActive();

        // Get grouper columns data
        $grouperData = $this->getGrouperColumnsData($advanceOrderIds);

        // Get exclude columns data (CAFETERIAS and CONVENIOS)
        $excludeData = $this->getExcludeColumnsData($advanceOrderIds, $config);

        // Merge grouper data with exclude data
        return $this->mergeColumnData($grouperData, $excludeData);
    }

    /**
     * Get column data for groupers only
     */
    protected function getGrouperColumnsData(array $advanceOrderIds): Collection
    {
        // Get unique order lines from companies that belong to report groupers
        $uniqueOrderLines = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->join('company_report_grouper', 'companies.id', '=', 'company_report_grouper.company_id')
            ->join('report_groupers', 'company_report_grouper.report_grouper_id', '=', 'report_groupers.id')
            ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds)
            ->where('report_groupers.is_active', true)
            ->select(
                'orders.id as order_id',
                'order_lines.product_id',
                'report_groupers.id as grouper_id',
                'report_groupers.name as grouper_name',
                'report_groupers.code as grouper_code',
                'report_groupers.display_order',
                'order_lines.quantity'
            )
            ->distinct() // Get unique combinations of order + product + grouper
            ->get();

        // Group by product and grouper
        return $uniqueOrderLines->groupBy('product_id')
            ->map(function ($productOrders) {
                return $productOrders->groupBy('grouper_id')
                    ->map(function ($grouperOrders) {
                        $first = $grouperOrders->first();

                        return (object) [
                            'column_id' => $first->grouper_id,
                            'column_name' => $first->grouper_name,
                            'display_name' => $first->grouper_name,
                            'display_order' => $first->display_order,
                            'total_quantity' => $grouperOrders->sum('quantity'),
                            'metadata' => [
                                'type' => 'grouper',
                                'grouper_id' => $first->grouper_id,
                                'grouper_code' => $first->grouper_code,
                            ]
                        ];
                    })->values();
            });
    }

    /**
     * Get column data for exclude columns (CAFETERIAS and CONVENIOS)
     */
    protected function getExcludeColumnsData(array $advanceOrderIds, ?ReportConfiguration $config): Collection
    {
        if (!$config || (!$config->exclude_cafeterias && !$config->exclude_agreements)) {
            return collect();
        }

        // Get IDs of companies that are already in groupers
        $companiesInGroupers = DB::table('company_report_grouper')
            ->join('report_groupers', 'company_report_grouper.report_grouper_id', '=', 'report_groupers.id')
            ->where('report_groupers.is_active', true)
            ->pluck('company_report_grouper.company_id')
            ->toArray();

        $excludeColumnsData = collect();

        // Get CAFETERIAS column data (Café role, NOT in groupers)
        if ($config->exclude_cafeterias) {
            $cafeteriasData = $this->getCafeteriasColumnData($advanceOrderIds, $companiesInGroupers);
            \Log::info('Cafeterias data before merge', [
                'keys' => $cafeteriasData->keys()->toArray(),
                'count' => $cafeteriasData->count()
            ]);

            foreach ($cafeteriasData as $productId => $columns) {
                $excludeColumnsData->put($productId, $columns);
            }
        }

        // Get CONVENIOS column data (Convenio role, NOT in groupers)
        if ($config->exclude_agreements) {
            $conveniosData = $this->getConveniosColumnData($advanceOrderIds, $companiesInGroupers);
            foreach ($conveniosData as $productId => $columns) {
                if ($excludeColumnsData->has($productId)) {
                    // Merge columns for this product
                    $excludeColumnsData->put($productId, $excludeColumnsData->get($productId)->merge($columns));
                } else {
                    $excludeColumnsData->put($productId, $columns);
                }
            }
        }

        \Log::info('Exclude columns data final', [
            'keys' => $excludeColumnsData->keys()->toArray(),
            'count' => $excludeColumnsData->count()
        ]);

        return $excludeColumnsData;
    }

    /**
     * Get CAFETERIAS column data
     */
    protected function getCafeteriasColumnData(array $advanceOrderIds, array $companiesInGroupers): Collection
    {
        $cafeRoleName = \App\Enums\RoleName::CAFE->value;

        $cafeteriasLines = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds)
            ->where('roles.name', $cafeRoleName)
            ->whereNotIn('companies.id', $companiesInGroupers)
            ->select(
                'orders.id as order_id',
                'order_lines.product_id',
                'order_lines.quantity'
            )
            ->distinct()
            ->get();

        if ($cafeteriasLines->isEmpty()) {
            return collect();
        }

        // Group by product
        $grouped = $cafeteriasLines->groupBy('product_id');

        \Log::info('Grouped cafeterias by product_id', [
            'keys' => $grouped->keys()->toArray(),
            'first_key' => $grouped->keys()->first(),
            'first_item_sample' => $cafeteriasLines->first()
        ]);

        $result = $grouped->mapWithKeys(function ($productLines, $productId) {
                \Log::info('Mapping cafeterias for product', [
                    'productId' => $productId,
                    'lines_count' => $productLines->count(),
                    'total_quantity' => $productLines->sum('quantity')
                ]);

                // Return with product_id as key to preserve grouping
                return [$productId => collect()->push((object) [
                    'column_id' => 'cafeterias',
                    'column_name' => 'CAFETERIAS',
                    'display_name' => 'CAFETERIAS',
                    'display_order' => 9000, // High number to show after groupers
                    'total_quantity' => $productLines->sum('quantity'),
                    'metadata' => [
                        'type' => 'exclude',
                        'exclude_type' => 'cafeterias',
                    ]
                ])];
            });

        \Log::info('Cafeterias result after mapWithKeys', [
            'keys' => $result->keys()->toArray(),
            'count' => $result->count()
        ]);

        return $result;
    }

    /**
     * Get CONVENIOS column data
     */
    protected function getConveniosColumnData(array $advanceOrderIds, array $companiesInGroupers): Collection
    {
        $convenioRoleName = \App\Enums\RoleName::AGREEMENT->value;

        \Log::info('getConveniosColumnData called', [
            'convenio_role_name' => $convenioRoleName,
            'companies_in_groupers' => $companiesInGroupers,
            'companies_in_groupers_count' => count($companiesInGroupers)
        ]);

        $conveniosLines = DB::table('advance_order_order_lines')
            ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
            ->join('orders', 'order_lines.order_id', '=', 'orders.id')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->join('companies', 'users.company_id', '=', 'companies.id')
            ->join('role_user', 'users.id', '=', 'role_user.user_id')
            ->join('roles', 'role_user.role_id', '=', 'roles.id')
            ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds)
            ->where('roles.name', $convenioRoleName)
            ->whereNotIn('companies.id', $companiesInGroupers)
            ->select(
                'orders.id as order_id',
                'order_lines.product_id',
                'order_lines.quantity'
            )
            ->distinct()
            ->get();

        \Log::info('getConveniosColumnData results', [
            'lines_count' => $conveniosLines->count(),
            'total_quantity' => $conveniosLines->sum('quantity'),
            'company_ids' => $conveniosLines->pluck('order_id')->unique()->toArray()
        ]);

        if ($conveniosLines->isEmpty()) {
            \Log::info('CONVENIOS lines is empty - will return empty column structure for first product');

            // Get the first product from advance orders to create an empty column
            $firstProductId = DB::table('advance_order_order_lines')
                ->join('order_lines', 'advance_order_order_lines.order_line_id', '=', 'order_lines.id')
                ->whereIn('advance_order_order_lines.advance_order_id', $advanceOrderIds)
                ->select('order_lines.product_id')
                ->first();

            if (!$firstProductId) {
                return collect();
            }

            // Return empty CONVENIOS column for the first product
            return collect([
                $firstProductId->product_id => collect()->push((object) [
                    'column_id' => 'convenios',
                    'column_name' => 'CONVENIOS',
                    'display_name' => 'CONVENIOS',
                    'display_order' => 9001,
                    'total_quantity' => 0, // Empty - no companies outside groupers
                    'metadata' => [
                        'type' => 'exclude',
                        'exclude_type' => 'convenios',
                    ]
                ])
            ]);
        }

        // Group by product
        return $conveniosLines->groupBy('product_id')
            ->mapWithKeys(function ($productLines, $productId) {
                // Return with product_id as key to preserve grouping
                return [$productId => collect()->push((object) [
                    'column_id' => 'convenios',
                    'column_name' => 'CONVENIOS',
                    'display_name' => 'CONVENIOS',
                    'display_order' => 9001, // Show after CAFETERIAS
                    'total_quantity' => $productLines->sum('quantity'),
                    'metadata' => [
                        'type' => 'exclude',
                        'exclude_type' => 'convenios',
                    ]
                ])];
            });
    }

    /**
     * Merge grouper data with exclude data
     */
    protected function mergeColumnData(Collection $grouperData, Collection $excludeData): Collection
    {
        \Log::info('mergeColumnData called', [
            'grouperData_count' => $grouperData->count(),
            'excludeData_count' => $excludeData->count(),
            'grouperData_keys' => $grouperData->keys()->toArray(),
            'excludeData_keys' => $excludeData->keys()->toArray()
        ]);

        if ($excludeData->isEmpty()) {
            \Log::warning('excludeData is empty, returning grouperData only');
            return $grouperData;
        }

        // Get all product IDs from both collections
        $allProductIds = $grouperData->keys()
            ->merge($excludeData->keys())
            ->unique();

        \Log::info('All product IDs to merge', ['product_ids' => $allProductIds->toArray()]);

        // Merge data for each product
        return $allProductIds->mapWithKeys(function ($productId) use ($grouperData, $excludeData) {
            $grouperColumns = $grouperData->get($productId, collect());
            $excludeColumns = $excludeData->get($productId, collect());

            \Log::info('Merging columns for product', [
                'product_id' => $productId,
                'grouperColumns_count' => $grouperColumns->count(),
                'excludeColumns_count' => $excludeColumns->count()
            ]);

            // Merge and sort by display_order
            $mergedColumns = $grouperColumns->merge($excludeColumns)
                ->sortBy('display_order')
                ->values();

            \Log::info('Merged result for product', [
                'product_id' => $productId,
                'merged_count' => $mergedColumns->count()
            ]);

            return [$productId => $mergedColumns];
        });
    }

    /**
     * Get column headers for groupers and exclude columns
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
     * Get column key prefix for groupers
     *
     * {@inheritDoc}
     */
    public function getColumnKeyPrefix(): string
    {
        return 'grouper_';
    }
}
