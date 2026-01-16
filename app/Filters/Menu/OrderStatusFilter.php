<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

class OrderStatusFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $user = $this->filter->getValue()['user'] ?? null;
        $status = $this->filter->getValue()['status'] ?? null;

        if ($user && $status) {
            $items->whereExists(function ($query) use ($user, $status) {
                $query->select(\DB::raw(1))
                    ->from('orders')
                    ->whereColumn(\DB::raw('DATE(orders.dispatch_date)'), 'menus.publication_date')
                    ->where('orders.user_id', $user->id)
                    ->where('orders.status', $status);
            });
        }

        return $next($items);
    }
}
