<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Jobs\CreateBestSellingProductsCategoryMenuJob;
use App\Models\Menu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BestSellingProductsCategoryMenuService
{
    /**
     * Process a collection of menus and dispatch jobs to create
     * best-selling products CategoryMenu for each one.
     * Only menus with "Café" role will be processed.
     *
     * @param Collection<Menu> $menus Collection of Menu models
     * @param string $startDate Start date for sales calculation
     * @param string $endDate End date for sales calculation
     * @param int $limit Number of top products to include
     * @return int Number of jobs dispatched
     */
    public function processMenus(
        Collection $menus,
        string $startDate,
        string $endDate,
        int $limit = 10
    ): int {
        $dispatchedCount = 0;

        Log::info('Starting BestSellingProductsCategoryMenuService', [
            'menus_count' => $menus->count(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => $limit,
        ]);

        $cafeMenus = $menus->filter(function (Menu $menu) {
            return $menu->rol?->name === RoleName::CAFE->value;
        });

        Log::info('Filtered menus for cafe role', [
            'total_menus' => $menus->count(),
            'cafe_menus' => $cafeMenus->count(),
        ]);

        foreach ($cafeMenus as $menu) {
            CreateBestSellingProductsCategoryMenuJob::dispatch(
                $menu->id,
                $startDate,
                $endDate,
                $limit
            );

            $dispatchedCount++;

            Log::debug('Dispatched CreateBestSellingProductsCategoryMenuJob', [
                'menu_id' => $menu->id,
                'menu_title' => $menu->title,
            ]);
        }

        Log::info('BestSellingProductsCategoryMenuService completed', [
            'dispatched_jobs' => $dispatchedCount,
        ]);

        return $dispatchedCount;
    }
}