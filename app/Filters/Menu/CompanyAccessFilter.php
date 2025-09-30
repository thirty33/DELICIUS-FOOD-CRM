<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class CompanyAccessFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $user = $this->filter->getValue()['user'] ?? null;

        if ($user) {
            $userCompanyId = $user->company_id;
            $userRoleIds = $user->roles->pluck('id')->toArray();
            $userPermissionIds = $user->permissions->pluck('id')->toArray();

            // Get publication dates where user's company has specific menus
            $companyMenuDates = $items->clone()
                ->whereHas('companies', function ($q) use ($userCompanyId) {
                    $q->where('companies.id', $userCompanyId);
                })
                ->pluck('publication_date')
                ->unique()
                ->toArray();

            $items = $items->where(function ($query) use ($companyMenuDates, $userCompanyId, $userRoleIds, $userPermissionIds) {
                // For dates with company-specific menus, show only those
                if (!empty($companyMenuDates)) {
                    $query->where(function ($companyQuery) use ($companyMenuDates, $userCompanyId) {
                        $companyQuery->whereIn('publication_date', $companyMenuDates)
                            ->whereHas('companies', function ($q) use ($userCompanyId) {
                                $q->where('companies.id', $userCompanyId);
                            });
                    });
                }

                // For other dates, show general menus matching user's role/permissions
                $query->orWhere(function ($generalQuery) use ($companyMenuDates, $userRoleIds, $userPermissionIds) {
                    if (!empty($companyMenuDates)) {
                        $generalQuery->whereNotIn('publication_date', $companyMenuDates);
                    }

                    $generalQuery->whereDoesntHave('companies')
                        ->where(function ($rolePermQuery) use ($userRoleIds, $userPermissionIds) {
                            if (!empty($userRoleIds)) {
                                $rolePermQuery->whereIn('role_id', $userRoleIds);
                            }
                            if (!empty($userPermissionIds)) {
                                $rolePermQuery->whereIn('permissions_id', $userPermissionIds);
                            }
                        });
                });
            });
        }

        return $next($items);
    }
}