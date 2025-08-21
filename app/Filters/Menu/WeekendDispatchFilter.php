<?php

namespace App\Filters\Menu;

use App\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class WeekendDispatchFilter extends Filter
{
    public function handle(Builder $items, \Closure $next): Builder
    {
        $allowWeekends = $this->filter->getValue()['allow_weekends'] ?? true;
        
        if (!$allowWeekends) {
            // Set timezone from config
            Carbon::setLocale(config('app.locale'));
            
            // Filter out weekend dispatch dates (Saturday = 6, Sunday = 0)
            $items = $items->whereRaw('WEEKDAY(publication_date) NOT IN (5, 6)');
        }

        return $next($items);
    }
}