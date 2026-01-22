<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Jobs\OrderCategoryMenuProductsByBestSellingJob;
use App\Models\Menu;
use App\Models\Parameter;
use Carbon\Carbon;
use Illuminate\Console\Command;

class OrderCategoryMenuProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'menus:order-category-products {--limit=5 : Number of menus to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Order products within category menus based on best-selling data for Cafe menus';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if auto-ordering is enabled (reuse existing parameter)
        $autoGenerate = Parameter::getValue(Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE, false);

        if (!$autoGenerate) {
            $this->info('Auto-ordering of category menu products is disabled.');
            return self::SUCCESS;
        }

        // Get parameters from database
        $dateRangeDays = Parameter::getValue(Parameter::BEST_SELLING_CATEGORY_DATE_RANGE_DAYS, 30);
        $menuLimit = (int) $this->option('limit');

        $this->info('Starting category menu products ordering...');

        // Get Cafe menus where products_ordered = false
        // Only future or today menus
        $menus = Menu::query()
            ->with('rol')
            ->whereHas('rol', function ($query) {
                $query->where('name', RoleName::CAFE->value);
            })
            ->where('products_ordered', false)
            ->where('publication_date', '>=', Carbon::today()->format('Y-m-d'))
            ->orderBy('publication_date', 'asc')
            ->limit($menuLimit)
            ->get();

        if ($menus->isEmpty()) {
            $this->info('No Cafe menus found to order.');
            return self::SUCCESS;
        }

        $this->info("Found {$menus->count()} Cafe menus to process.");

        // Calculate date range based on parameter
        $endDate = Carbon::now()->format('Y-m-d');
        $startDate = Carbon::now()->subDays($dateRangeDays)->format('Y-m-d');

        $this->info("Date range for sales data: {$startDate} to {$endDate} ({$dateRangeDays} days)");

        $dispatchedCount = 0;

        foreach ($menus as $menu) {
            OrderCategoryMenuProductsByBestSellingJob::dispatch(
                $menu->id,
                $startDate,
                $endDate
            );
            $dispatchedCount++;

            $this->line("  - Dispatched job for menu: {$menu->title} (ID: {$menu->id})");
        }

        $this->info("Dispatched {$dispatchedCount} jobs successfully.");

        return self::SUCCESS;
    }
}