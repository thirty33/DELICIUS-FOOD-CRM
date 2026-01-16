<?php

namespace App\Repositories;

use App\Models\Menu;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Pipeline\Pipeline;
use App\Filters\FilterValue;
use App\Enums\Filters\MenuFilters;

class MenuRepository
{
    /**
     * Get available menus for a specific user with all business logic filters applied.
     *
     * @param User $user The effective user (delegate user if delegating)
     * @param int|null $limit
     * @param User|null $userForValidations The user for validations (super_master_user if delegating)
     * @param array $searchFilters Additional search filters:
     *   - 'start_date': Start date for date range filter
     *   - 'end_date': End date for date range filter
     *   - 'order_status': Filter menus where user has orders with this status
     * @return Collection
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
        $orderQuery = function ($selectRaw) use ($user) {
            return Order::selectRaw($selectRaw)
                ->where('user_id', $user->id)
                ->where('status', \App\Enums\OrderStatus::PROCESSED->value)
                ->whereRaw('DATE(dispatch_date) = DATE(menus.publication_date)');
        };

        $menus = $menus->addSelect([
            'has_order' => $orderQuery('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END')->limit(1),
            'order_id' => $orderQuery('MAX(id)')
        ]);

        if ($limit) {
            $menus->limit($limit);
        }

        return $menus->get();
    }
}
