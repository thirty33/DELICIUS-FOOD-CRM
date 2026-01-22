<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Models\Menu;
use App\Models\Parameter;
use App\Services\BestSellingProductsCategoryMenuService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateBestSellingCategoryMenu extends Command
{
    protected BestSellingProductsCategoryMenuService $service;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'menus:generate-best-selling-category {--limit=5 : Number of menus to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate best-selling products dynamic category for recent Cafe menus';

    public function __construct(BestSellingProductsCategoryMenuService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Check if auto-generation is enabled
        $autoGenerate = Parameter::getValue(Parameter::BEST_SELLING_CATEGORY_AUTO_GENERATE, false);

        if (!$autoGenerate) {
            $this->info('Auto-generation of best-selling category is disabled.');
            return self::SUCCESS;
        }

        // Get parameters from database
        $productsLimit = Parameter::getValue(Parameter::BEST_SELLING_CATEGORY_PRODUCTS_LIMIT, 10);
        $dateRangeDays = Parameter::getValue(Parameter::BEST_SELLING_CATEGORY_DATE_RANGE_DAYS, 30);
        $menuLimit = (int) $this->option('limit');

        $this->info('Starting best-selling category generation...');

        // Get recent Cafe menus ordered by publication_date descending (only future or today)
        // Exclude menus that already have a dynamic category
        $menus = Menu::query()
            ->with('rol')
            ->whereHas('rol', function ($query) {
                $query->where('name', RoleName::CAFE->value);
            })
            ->whereDoesntHave('categoryMenus.category', function ($query) {
                $query->where('is_dynamic', true);
            })
            ->where('publication_date', '>=', Carbon::today()->format('Y-m-d'))
            ->orderBy('publication_date', 'desc')
            ->limit($menuLimit)
            ->get();

        if ($menus->isEmpty()) {
            $this->info('No Cafe menus found.');
            return self::SUCCESS;
        }

        $this->info("Found {$menus->count()} Cafe menus to process.");

        // Calculate date range based on parameter
        $endDate = Carbon::now()->format('Y-m-d');
        $startDate = Carbon::now()->subDays($dateRangeDays)->format('Y-m-d');

        $this->info("Date range: {$startDate} to {$endDate} ({$dateRangeDays} days)");
        $this->info("Products limit: {$productsLimit}");

        // Process menus using the service
        $dispatchedCount = $this->service->processMenus(
            $menus,
            $startDate,
            $endDate,
            $productsLimit
        );

        $this->info("Dispatched {$dispatchedCount} jobs successfully.");

        return self::SUCCESS;
    }
}