<?php

namespace App\Repositories;

use App\Models\DispatchRule;
use App\Models\User;
use App\Models\Order;

class DispatchRuleRepository
{
    /**
     * Calculate dispatch cost for an order based on dispatch rules
     */
    public function calculateDispatchCost(Order $order): int
    {
        $user = $order->user;
        
        if (!$user || !$user->company_id) {
            return 0;
        }

        $companyId = $user->company_id;
        $branchId = $user->branch_id ?? $order->branch_id;

        if (!$branchId) {
            return 0;
        }

        $applicableRule = $this->findApplicableRule($companyId, $branchId);

        if (!$applicableRule) {
            return 0;
        }

        return $this->calculateCostFromRanges($applicableRule, $order->total);
    }

    /**
     * Find the applicable dispatch rule for a company and branch
     */
    private function findApplicableRule(int $companyId, int $branchId): ?DispatchRule
    {
        return DispatchRule::where('active', true)
            ->where(function ($query) use ($companyId, $branchId) {
                $query->whereHas('companies', function ($companyQuery) use ($companyId) {
                    $companyQuery->where('company_id', $companyId);
                })->whereHas('branches', function ($branchQuery) use ($branchId) {
                    $branchQuery->where('branch_id', $branchId);
                });
            })
            ->orderBy('priority', 'asc')
            ->first();
    }

    /**
     * Calculate dispatch cost from rule ranges based on order total
     */
    private function calculateCostFromRanges(DispatchRule $rule, int $orderTotal): int
    {
        $ranges = $rule->ranges()->orderBy('min_amount', 'asc')->get();

        foreach ($ranges as $range) {
            if ($orderTotal >= $range->min_amount) {
                if ($range->max_amount === null || $orderTotal < $range->max_amount) {
                    return $range->dispatch_cost;
                }
            }
        }

        return 0;
    }
}