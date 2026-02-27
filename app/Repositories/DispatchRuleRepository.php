<?php

namespace App\Repositories;

use App\Models\DispatchRule;
use App\Models\Order;

class DispatchRuleRepository
{
    /**
     * Find a dispatch rule by its name.
     */
    public function findByName(string $name): ?DispatchRule
    {
        return DispatchRule::query()
            ->where('name', $name)
            ->first();
    }

    /**
     * Calculate dispatch cost for an order based on dispatch rules
     */
    public function calculateDispatchCost(Order $order): int
    {
        $user = $order->user;

        if (! $user || ! $user->company_id) {
            return 0;
        }

        $companyId = $user->company_id;
        $branchId = $user->branch_id ?? $order->branch_id;

        if (! $branchId) {
            return 0;
        }

        $applicableRule = $this->findApplicableRule($companyId, $branchId);

        if (! $applicableRule) {
            return 0;
        }

        return $this->calculateCostFromRanges($applicableRule, $order->total);
    }

    /**
     * Get the applicable dispatch rule for a user
     */
    public function getDispatchRuleForUser(\App\Models\User $user): ?DispatchRule
    {
        if (! $user->company_id || ! $user->branch_id) {
            return null;
        }

        return $this->findApplicableRule($user->company_id, $user->branch_id);
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

    /**
     * Get shipping threshold information for showing next better shipping rate
     */
    public function getShippingThresholdInfo(Order $order): array
    {
        $user = $order->user;

        if (! $user || ! $user->company_id) {
            return $this->getEmptyThresholdInfo();
        }

        $companyId = $user->company_id;
        $branchId = $user->branch_id ?? $order->branch_id;

        if (! $branchId) {
            return $this->getEmptyThresholdInfo();
        }

        $applicableRule = $this->findApplicableRule($companyId, $branchId);

        if (! $applicableRule) {
            return $this->getEmptyThresholdInfo();
        }

        return $this->calculateThresholdInfo($applicableRule, $order->total);
    }

    /**
     * Calculate threshold information based on dispatch rule and order total
     */
    private function calculateThresholdInfo(DispatchRule $rule, int $orderTotal): array
    {
        $ranges = $rule->ranges()->orderBy('min_amount', 'asc')->get();

        // Find current range
        $currentRange = null;
        $currentDispatchCost = 0;

        foreach ($ranges as $range) {
            if ($orderTotal >= $range->min_amount) {
                if ($range->max_amount === null || $orderTotal < $range->max_amount) {
                    $currentRange = $range;
                    $currentDispatchCost = $range->dispatch_cost;
                    break;
                }
            }
        }

        // Find next better range (with lower dispatch cost)
        $nextBetterRange = null;

        foreach ($ranges as $range) {
            // Skip if this range starts before or at current total
            if ($range->min_amount <= $orderTotal) {
                continue;
            }

            // Check if this range has better (lower) dispatch cost
            if ($range->dispatch_cost < $currentDispatchCost) {
                // If we haven't found a better range yet, or this one is closer
                if (! $nextBetterRange || $range->min_amount < $nextBetterRange->min_amount) {
                    $nextBetterRange = $range;
                }
            }
        }

        // If there's a better range available
        if ($nextBetterRange) {
            return [
                'has_better_rate' => true,
                'next_threshold_amount' => $nextBetterRange->min_amount,
                'next_threshold_cost' => $nextBetterRange->dispatch_cost,
                'amount_to_reach' => $nextBetterRange->min_amount - $orderTotal,
                'current_cost' => $currentDispatchCost,
                'savings' => $currentDispatchCost - $nextBetterRange->dispatch_cost,
            ];
        }

        return $this->getEmptyThresholdInfo();
    }

    /**
     * Return empty threshold info when no better rate is available
     */
    private function getEmptyThresholdInfo(): array
    {
        return [
            'has_better_rate' => false,
            'next_threshold_amount' => null,
            'next_threshold_cost' => null,
            'amount_to_reach' => null,
            'current_cost' => null,
            'savings' => null,
        ];
    }
}
