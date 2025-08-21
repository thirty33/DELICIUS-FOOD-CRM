<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class RolePermissionFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $user = $this->filter->getValue()['user'] ?? null;
        
        if ($user) {
            $userRoleIds = $user->roles->pluck('id')->toArray();
            $userPermissionIds = $user->permissions->pluck('id')->toArray();

            $items = $items->where(function ($q) use ($userRoleIds, $userPermissionIds) {
                if (!empty($userRoleIds)) {
                    $q->whereIn('role_id', $userRoleIds);
                }
                
                if (!empty($userPermissionIds)) {
                    $q->orWhereIn('permissions_id', $userPermissionIds);
                }
            });
        }

        return $next($items);
    }
}