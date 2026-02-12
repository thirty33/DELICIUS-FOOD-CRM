<?php

namespace App\Actions\Products;

use App\Models\Category;
use App\Models\MasterCategory;
use App\Repositories\MasterCategoryRepository;

class SyncMasterCategoriesAction
{
    public function __construct(
        private MasterCategoryRepository $repository,
    ) {}

    /**
     * Parse comma-separated master category names, create if needed, and sync to the category.
     *
     * @param Category $category
     * @param string $rawValue Comma-separated master category names from Excel
     */
    public function execute(Category $category, string $rawValue): void
    {
        $names = array_filter(array_map('trim', explode(',', $rawValue)));

        if (empty($names)) {
            return;
        }

        $ids = [];

        foreach ($names as $name) {
            $masterCategory = $this->repository->findByName($name);

            if (! $masterCategory) {
                $masterCategory = MasterCategory::create(['name' => $name]);
            }

            $ids[] = $masterCategory->id;
        }

        $category->masterCategories()->syncWithoutDetaching($ids);
    }
}
