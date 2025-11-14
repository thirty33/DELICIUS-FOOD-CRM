<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * Contract for providing column data in consolidated reports
 *
 * This interface defines the contract for services that provide additional column data
 * in consolidated reports (e.g., discriminated companies, report groupers).
 *
 * Implementations can use different strategies to retrieve and group data
 * (e.g., companies.exclude_from_consolidated_report, report_groupers, etc.)
 */
interface ReportColumnDataProviderInterface
{
    /**
     * Get column data for products in the given advance orders
     *
     * Returns a collection grouped by product_id, where each product contains
     * a collection of column entries with their respective data.
     *
     * Structure:
     * [
     *     product_id => [
     *         (object) [
     *             'column_id' => int,           // company_id, grouper_id, etc.
     *             'column_name' => string,      // Full name
     *             'display_name' => string,     // Name to display (fantasy_name or name)
     *             'display_order' => int,       // Order for sorting
     *             'total_quantity' => int,      // Sum of quantities
     *             'metadata' => array           // Additional data (optional)
     *         ],
     *         ...
     *     ],
     *     ...
     * ]
     *
     * @param array $advanceOrderIds Array of advance order IDs
     * @return Collection Grouped by product_id → collection of column objects
     */
    public function getColumnData(array $advanceOrderIds): Collection;

    /**
     * Get column headers for display in report
     *
     * Returns an associative array where keys are column identifiers
     * (with prefix) and values are display names for headers.
     *
     * Example:
     * [
     *     'company_2' => 'Company 2',
     *     'company_3' => 'Company 3',
     * ]
     * or
     * [
     *     'grouper_123' => 'CAFETERIA ALMA TERRA',
     *     'grouper_456' => 'CAFETERIA BOUNNA',
     * ]
     *
     * @param array $advanceOrderIds Array of advance order IDs
     * @return array Key-value pairs of column_key => display_name
     */
    public function getColumnHeaders(array $advanceOrderIds): array;

    /**
     * Get the column key prefix used by this provider
     *
     * This prefix is used to build unique column keys for identification.
     *
     * Examples: 'company_', 'grouper_', 'region_'
     *
     * @return string Column key prefix
     */
    public function getColumnKeyPrefix(): string;
}
