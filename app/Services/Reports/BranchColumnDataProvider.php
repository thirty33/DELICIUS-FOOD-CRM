<?php

namespace App\Services\Reports;

use App\Contracts\ColumnDataProviderInterface;
use App\Models\AdvanceOrderOrderLine;
use Illuminate\Support\Collection;

/**
 * Provides column data based on Branches for consolidated reports.
 *
 * This implementation extracts columns from the branch relationship:
 * - Each unique branch becomes a column
 * - Column key is branch_id
 * - Column name is branch.fantasy_name
 */
class BranchColumnDataProvider implements ColumnDataProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function getEagerLoadRelationships(): array
    {
        return ['associatedOrderLines.order.user.branch'];
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnNames(Collection $advanceOrders): array
    {
        return $advanceOrders
            ->flatMap(function ($advanceOrder) {
                return $advanceOrder->associatedOrderLines
                    ->map(fn ($aoOrderLine) => $aoOrderLine->order->user->branch)
                    ->filter()
                    ->unique('id')
                    ->pluck('fantasy_name');
            })
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * {@inheritdoc}
     */
    public function getColumnForOrderLine(AdvanceOrderOrderLine $orderLine): ?array
    {
        $branch = $orderLine->order->user->branch ?? null;

        if (!$branch) {
            return null;
        }

        return [
            'column_key' => $branch->id,
            'column_name' => $branch->fantasy_name,
        ];
    }
}