<?php

namespace App\Repositories;

use App\Models\OrderRule;
use App\Models\User;
use Illuminate\Support\Collection;

class OrderRuleRepository
{
    /**
     * Get subcategory exclusions for a user based on their role, permission, and company.
     *
     * Priority order:
     * 1. Order rules associated with the user's company
     * 2. General order rules (not associated with any specific company)
     *
     * Filters:
     * - Active rules only
     * - Type: 'subcategory_exclusion'
     * - Matching user's role and permission
     * - Ordered by priority (lowest priority number = highest priority)
     *
     * @param User $user
     * @return Collection Collection of OrderRuleSubcategoryExclusion
     */
    public function getSubcategoryExclusionsForUser(User $user): Collection
    {
        $roleId = $user->roles->first()?->id;
        $permissionId = $user->permissions->first()?->id;

        if (!$roleId || !$permissionId) {
            return collect();
        }

        // Try to find order rule associated with user's company
        $orderRule = OrderRule::query()
            ->active()
            ->where('rule_type', 'subcategory_exclusion')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->whereHas('companies', function ($query) use ($user) {
                $query->where('companies.id', $user->company_id);
            })
            ->orderBy('priority', 'asc') // Lower priority number = higher priority
            ->first();

        // If no company-specific rule found, get general rule (no company association)
        if (!$orderRule) {
            $orderRule = OrderRule::query()
                ->active()
                ->where('rule_type', 'subcategory_exclusion')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->whereDoesntHave('companies') // No company associations
                ->orderBy('priority', 'asc')
                ->first();
        }

        if (!$orderRule) {
            return collect();
        }

        // Return the subcategory exclusions for the found order rule
        return $orderRule->subcategoryExclusions()
            ->with(['subcategory', 'excludedSubcategory'])
            ->get();
    }

    /**
     * Get order rule for a user based on their role, permission, and company.
     *
     * @param User $user
     * @param string $ruleType
     * @return OrderRule|null
     */
    public function getOrderRuleForUser(User $user, string $ruleType = 'subcategory_exclusion'): ?OrderRule
    {
        $roleId = $user->roles->first()?->id;
        $permissionId = $user->permissions->first()?->id;

        if (!$roleId || !$permissionId) {
            return null;
        }

        // Try to find order rule associated with user's company
        $orderRule = OrderRule::query()
            ->active()
            ->where('rule_type', $ruleType)
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->whereHas('companies', function ($query) use ($user) {
                $query->where('companies.id', $user->company_id);
            })
            ->orderBy('priority', 'asc')
            ->first();

        // If no company-specific rule found, get general rule
        if (!$orderRule) {
            $orderRule = OrderRule::query()
                ->active()
                ->where('rule_type', $ruleType)
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->whereDoesntHave('companies')
                ->orderBy('priority', 'asc')
                ->first();
        }

        return $orderRule;
    }

    /**
     * Get subcategory product limits for a user based on their role, permission, and company.
     *
     * Priority order:
     * 1. Order rules associated with the user's company
     * 2. General order rules (not associated with any specific company)
     *
     * Filters:
     * - Active rules only
     * - Type: 'product_limit_per_subcategory'
     * - Matching user's role and permission
     * - Ordered by priority (lowest priority number = highest priority)
     *
     * @param User $user
     * @return Collection Collection of OrderRuleSubcategoryLimit with format: ['subcategory_name' => max_products]
     */
    public function getSubcategoryLimitsForUser(User $user): Collection
    {
        $roleId = $user->roles->first()?->id;
        $permissionId = $user->permissions->first()?->id;

        if (!$roleId || !$permissionId) {
            return collect();
        }

        // Try to find order rule associated with user's company
        $orderRule = OrderRule::query()
            ->active()
            ->where('rule_type', 'product_limit_per_subcategory')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->whereHas('companies', function ($query) use ($user) {
                $query->where('companies.id', $user->company_id);
            })
            ->orderBy('priority', 'asc') // Lower priority number = higher priority
            ->first();

        // If no company-specific rule found, get general rule (no company association)
        if (!$orderRule) {
            $orderRule = OrderRule::query()
                ->active()
                ->where('rule_type', 'product_limit_per_subcategory')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->whereDoesntHave('companies') // No company associations
                ->orderBy('priority', 'asc')
                ->first();
        }

        if (!$orderRule) {
            return collect();
        }

        // Return the subcategory limits as a keyed collection: ['subcategory_name' => max_products]
        return $orderRule->subcategoryLimits()
            ->with('subcategory')
            ->get()
            ->mapWithKeys(function ($limit) {
                return [$limit->subcategory->name => $limit->max_products];
            });
    }
}
