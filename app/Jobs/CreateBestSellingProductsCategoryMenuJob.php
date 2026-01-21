<?php

namespace App\Jobs;

use App\Contracts\BestSellingProductsRepositoryInterface;
use App\Repositories\CategoryMenuRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\MenuRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateBestSellingProductsCategoryMenuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    protected int $menuId;
    protected string $startDate;
    protected string $endDate;
    protected int $limit;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $menuId,
        string $startDate,
        string $endDate,
        int $limit = 10
    ) {
        $this->menuId = $menuId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->limit = $limit;
    }

    /**
     * Execute the job.
     */
    public function handle(
        BestSellingProductsRepositoryInterface $bestSellingProductsRepository,
        MenuRepository $menuRepository,
        CategoryRepository $categoryRepository,
        CategoryMenuRepository $categoryMenuRepository
    ): void {
        Log::info('Starting CreateBestSellingProductsCategoryMenuJob', [
            'menu_id' => $this->menuId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'limit' => $this->limit,
        ]);

        $menu = $menuRepository->findWithRole($this->menuId);

        if (!$menu) {
            Log::warning('Menu not found', ['menu_id' => $this->menuId]);
            return;
        }

        $roleName = $menu->rol?->name;

        if (!$roleName) {
            Log::warning('Menu has no associated role', ['menu_id' => $this->menuId]);
            return;
        }

        $dynamicCategory = $categoryRepository->getDynamicCategory();

        if (!$dynamicCategory) {
            Log::warning('No dynamic category found');
            return;
        }

        $productIds = $bestSellingProductsRepository->getBestSellingProductIdsByRole(
            $roleName,
            $this->startDate,
            $this->endDate,
            $this->limit
        );

        if (empty($productIds)) {
            Log::info('No best-selling products found', [
                'menu_id' => $this->menuId,
                'role' => $roleName,
            ]);
            return;
        }

        $categoryMenu = $categoryMenuRepository->createOrUpdateWithProducts(
            $menu->id,
            $dynamicCategory->id,
            $productIds
        );

        Log::info('Best-selling products CategoryMenu created', [
            'menu_id' => $this->menuId,
            'category_menu_id' => $categoryMenu->id,
            'products_count' => count($productIds),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateBestSellingProductsCategoryMenuJob failed', [
            'menu_id' => $this->menuId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}