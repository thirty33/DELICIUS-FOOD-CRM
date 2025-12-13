<?php

namespace App\Contracts;

use App\Models\AdvanceOrderOrderLine;
use Illuminate\Support\Collection;

/**
 * Contract for providing column data in consolidated reports.
 *
 * This interface abstracts how dynamic columns are determined for reports.
 * Implementations can provide columns based on:
 * - Branches (BranchColumnDataProvider)
 * - Report Groupers (GrouperColumnDataProvider)
 *
 * The repository uses GENERIC keys (column_key, column_name) so it remains
 * completely agnostic to whether it's working with branches or groupers.
 */
interface ColumnDataProviderInterface
{
    /**
     * Get the relationships to eager load for optimal performance.
     *
     * Returns an array of relationship paths that should be loaded
     * when querying AdvanceOrderOrderLines.
     *
     * For branch-based: ['order.user.branch']
     * For grouper-based: ['order.user.company.reportGroupers']
     *
     * @return array Array of relationship paths for eager loading
     */
    public function getEagerLoadRelationships(): array;

    /**
     * Get all column names for the report (dynamic columns).
     *
     * Returns the names that will be used as column headers in the report.
     * The order of names determines the column order in the output.
     *
     * @param Collection $advanceOrders Collection of AdvanceOrder models
     * @return array Array of column names sorted for display
     */
    public function getColumnNames(Collection $advanceOrders): array;

    /**
     * Get the column assignment for a given order line.
     *
     * Returns an array with generic keys that the repository uses:
     * - 'column_key': Unique identifier for grouping (branch_id or grouper_id)
     * - 'column_name': Display name for the column header
     *
     * Returns null if the order line doesn't belong to any column.
     *
     * @param AdvanceOrderOrderLine $orderLine The order line being processed
     * @return array{column_key: int|string, column_name: string}|null
     */
    public function getColumnForOrderLine(AdvanceOrderOrderLine $orderLine): ?array;
}
