<?php

namespace App\Repositories;

use App\Enums\Filters\MenuFilters;
use App\Enums\RoleName;
use App\Filters\FilterValue;
use App\Models\Menu;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;

class MenuRepository
{
    /**
     * Find a menu by ID with its role relationship.
     */
    public function findWithRole(int $menuId): ?Menu
    {
        return Menu::with('rol')->find($menuId);
    }

    /**
     * Get available menus for a specific user with all business logic filters applied.
     *
     * @param  User  $user  The effective user (delegate user if delegating)
     * @param  User|null  $userForValidations  The user for validations (super_master_user if delegating)
     * @param  array  $searchFilters  Additional search filters:
     *                                - 'start_date': Start date for date range filter
     *                                - 'end_date': End date for date range filter
     *                                - 'order_status': Filter menus where user has orders with this status
     */
    public function getAvailableMenusForUser(User $user, ?int $limit = null, ?User $userForValidations = null, array $searchFilters = []): Collection
    {
        // Use userForValidations if provided, otherwise use user
        $validationUser = $userForValidations ?? $user;

        $baseQuery = Menu::query();

        $filters = [
            MenuFilters::Active->create(new FilterValue(null)),
            MenuFilters::PublicationDate->create(new FilterValue(['date' => Carbon::now()->startOfDay()])),
            MenuFilters::RolePermission->create(new FilterValue(['user' => $user])),
            MenuFilters::CompanyAccess->create(new FilterValue(['user' => $user])),
            MenuFilters::LateOrders->create(new FilterValue(['user' => $validationUser])),
            MenuFilters::WeekendDispatch->create(new FilterValue(['allow_weekends' => $validationUser->allow_weekend_orders])),
            MenuFilters::DateRange->create(new FilterValue([
                'start_date' => $searchFilters['start_date'] ?? null,
                'end_date' => $searchFilters['end_date'] ?? null,
            ])),
            MenuFilters::OrderStatus->create(new FilterValue([
                'user' => $user,
                'status' => $searchFilters['order_status'] ?? null,
            ])),
            MenuFilters::Sort->create(new FilterValue(['field' => 'publication_date', 'direction' => 'asc'])),
        ];

        $menus = app(Pipeline::class)
            ->send($baseQuery)
            ->through($filters)
            ->thenReturn();

        // Base order query for reuse
        // Include orders with PROCESSED or PARTIALLY_SCHEDULED status
        $orderQuery = function ($selectRaw) use ($user) {
            return Order::selectRaw($selectRaw)
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    \App\Enums\OrderStatus::PROCESSED->value,
                    \App\Enums\OrderStatus::PARTIALLY_SCHEDULED->value,
                ])
                ->whereRaw('DATE(dispatch_date) = DATE(menus.publication_date)');
        };

        $menus = $menus->addSelect([
            'has_order' => $orderQuery('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')->limit(1),
            'order_id' => $orderQuery('id')->limit(1),
            'order_status' => $orderQuery('status')->limit(1),
        ]);

        if ($limit) {
            $menus->limit($limit);
        }

        return $menus->get();
    }

    /**
     * Get active menus closing within the given time window, excluding weekend publication dates.
     */
    public function getMenusClosingSoon(Carbon $closingBefore, array $roleIds, array $permissionIds): Collection
    {
        return Menu::query()
            ->where('active', true)
            ->where('max_order_date', '>', now())
            ->where('max_order_date', '<=', $closingBefore)
            ->whereRaw('DAYOFWEEK(publication_date) NOT IN (1, 7)')
            ->where(function ($query) use ($roleIds) {
                $query->whereIn('role_id', $roleIds)
                    ->orWhereNull('role_id');
            })
            ->where(function ($query) use ($permissionIds) {
                $query->whereIn('permissions_id', $permissionIds)
                    ->orWhereNull('permissions_id');
            })
            ->get();
    }

    /**
     * Get Cafe menus pending display_order application, ordered by most recent first.
     */
    public function getPendingCafeMenusForDisplayOrder(int $limit): Collection
    {
        return Menu::query()
            ->whereHas('rol', fn ($q) => $q->where('name', RoleName::CAFE->value))
            ->where('products_ordered', false)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get active menus created since a given date, filtered by role and permission.
     */
    public function getMenusCreatedSince(Carbon $since, array $roleIds, array $permissionIds): Collection
    {
        return Menu::query()
            ->where('active', true)
            ->where('created_at', '>=', $since)
            ->where(function ($query) use ($roleIds) {
                $query->whereIn('role_id', $roleIds)
                    ->orWhereNull('role_id');
            })
            ->where(function ($query) use ($permissionIds) {
                $query->whereIn('permissions_id', $permissionIds)
                    ->orWhereNull('permissions_id');
            })
            ->get();
    }
}
