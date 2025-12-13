<?php

namespace App\Services\Reports;

use App\Contracts\ColumnDataProviderInterface;
use App\Models\AdvanceOrderOrderLine;
use App\Models\ReportGrouper;
use Illuminate\Support\Collection;

/**
 * Provides column data based on Report Groupers for consolidated reports.
 *
 * This implementation extracts columns from the report groupers configuration:
 * - Each active grouper becomes a column
 * - Column key is grouper_id
 * - Column name is grouper.name
 * - Groupers can be mapped via:
 *   1. branch_report_grouper (specific branch → grouper)
 *   2. company_report_grouper (all branches of company → grouper)
 *
 * Priority: Branch-level mapping takes precedence over company-level.
 *
 * Key difference from BranchColumnDataProvider:
 * - Branches: 1 order → 1 branch → 1 column
 * - Groupers: N orders from N companies/branches → 1 grouper → 1 column (quantities summed)
 */
class GrouperColumnDataProvider implements ColumnDataProviderInterface
{
    /**
     * Cache of groupers loaded for this request
     */
    protected ?Collection $groupersCache = null;

    /**
     * {@inheritdoc}
     */
    public function getEagerLoadRelationships(): array
    {
        return ['associatedOrderLines.order.user.branch', 'associatedOrderLines.order.user.company'];
    }

    /**
     * {@inheritdoc}
     *
     * Gets column names from ALL active groupers that have branches or companies
     * matching ANY of the companies present in the advance orders.
     *
     * IMPORTANT: Returns ALL groupers for companies that have ANY orders in the report,
     * even if a specific grouper doesn't have orders. This ensures column alignment
     * between schema headers and data rows.
     *
     * Example:
     * - Company A has orders → Grouper "OTERO" (contains Company A)
     * - Company B has NO orders but is in same grouper system
     * - Grouper "ALIACE" (contains Company B) will also be included
     *
     * This prevents column misalignment when some groupers have no data.
     */
    public function getColumnNames(Collection $advanceOrders): array
    {
        // Get all company IDs from advance orders
        $companyIds = collect();

        $advanceOrders->each(function ($advanceOrder) use (&$companyIds) {
            $advanceOrder->associatedOrderLines->each(function ($aoOrderLine) use (&$companyIds) {
                $user = $aoOrderLine->order->user ?? null;
                if ($user && $user->company_id) {
                    $companyIds->push($user->company_id);
                }
            });
        });

        $companyIds = $companyIds->unique()->values();

        // Get ALL active groupers ordered by display_order
        // This ensures consistent column order regardless of which groupers have data
        $groupers = ReportGrouper::where('is_active', true)
            ->with(['branches', 'companies'])
            ->orderBy('display_order')
            ->get();

        // Cache for later use in getColumnForOrderLine
        $this->groupersCache = $groupers;

        return $groupers->pluck('name')->toArray();
    }

    /**
     * {@inheritdoc}
     *
     * Finds the grouper for an order line.
     * Priority: Branch-level → Company-level
     */
    public function getColumnForOrderLine(AdvanceOrderOrderLine $orderLine): ?array
    {
        $user = $orderLine->order->user ?? null;

        if (!$user) {
            return null;
        }

        $branch = $user->branch ?? null;
        $company = $user->company ?? null;

        // Use cached groupers if available, otherwise query
        $groupers = $this->groupersCache ?? ReportGrouper::where('is_active', true)
            ->with(['branches', 'companies'])
            ->orderBy('display_order')
            ->get();

        // First, try to find a grouper by branch (more specific)
        if ($branch) {
            $grouperByBranch = $groupers->first(function ($grouper) use ($branch) {
                return $grouper->branches->contains('id', $branch->id);
            });

            if ($grouperByBranch) {
                return [
                    'column_key' => $grouperByBranch->id,
                    'column_name' => $grouperByBranch->name,
                ];
            }
        }

        // Fall back to company-level grouper
        if ($company) {
            $grouperByCompany = $groupers->first(function ($grouper) use ($company) {
                return $grouper->companies->contains('id', $company->id);
            });

            if ($grouperByCompany) {
                return [
                    'column_key' => $grouperByCompany->id,
                    'column_name' => $grouperByCompany->name,
                ];
            }
        }

        return null;
    }
}