<?php

namespace App\Filters\Order;

use App\Filters\Filter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class TimePeriodFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        if (!$this->filter->getValue()) {
            return $next($items);
        }

        $timePeriod = $this->filter->getValue();
        $now = Carbon::now();

        $items->where(function (Builder $query) use ($timePeriod, $now) {
            switch ($timePeriod) {
                case 'this_week':
                    $query->whereBetween('dispatch_date', [
                        $now->copy()->startOfWeek(), 
                        $now->endOfWeek()
                    ]);
                    break;
                case 'this_month':
                    $query->whereBetween('dispatch_date', [
                        $now->copy()->startOfMonth(), 
                        $now->endOfMonth()
                    ]);
                    break;
                case 'last_3_months':
                    $query->whereBetween('dispatch_date', [
                        $now->copy()->subMonths(3)->startOfMonth(), 
                        $now->endOfMonth()
                    ]);
                    break;
                case 'last_6_months':
                    $query->whereBetween('dispatch_date', [
                        $now->copy()->subMonths(6)->startOfMonth(), 
                        $now->endOfMonth()
                    ]);
                    break;
                case 'this_year':
                    $query->whereBetween('dispatch_date', [
                        $now->copy()->startOfYear(), 
                        $now->endOfYear()
                    ]);
                    break;
            }
        });

        return $next($items);
    }
}