<?php

namespace App\Console\Commands;

use App\Models\Parameter;
use App\Services\ProductDisplayOrderService;
use Illuminate\Console\Command;

class ApplyProductDisplayOrderCommand extends Command
{
    protected $signature = 'menus:apply-product-display-order {--limit=5 : Number of menus to process}';

    protected $description = 'Apply products.display_order to category_menu_product.display_order for Cafe menus';

    public function __construct(
        private ProductDisplayOrderService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $autoApply = Parameter::getValue(Parameter::PRODUCT_DISPLAY_ORDER_AUTO_APPLY, false);

        if (! $autoApply) {
            $this->info('Auto-apply of product display order is disabled.');

            return self::SUCCESS;
        }

        $menuLimit = (int) $this->option('limit');

        $menus = $this->service->dispatchOrderingJobs($menuLimit);

        if ($menus->isEmpty()) {
            $this->info('No Cafe menus found to process.');

            return self::SUCCESS;
        }

        $this->info("Found {$menus->count()} Cafe menus to process.");

        foreach ($menus as $menu) {
            $this->line("  - Dispatched job for menu: {$menu->title} (ID: {$menu->id})");
        }

        $this->info("Dispatched {$menus->count()} jobs successfully.");

        return self::SUCCESS;
    }
}
