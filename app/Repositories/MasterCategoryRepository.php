<?php

namespace App\Repositories;

use App\Models\MasterCategory;
use Illuminate\Support\Collection;

class MasterCategoryRepository
{
    /**
     * Find a master category by exact name.
     */
    public function findByName(string $name): ?MasterCategory
    {
        return MasterCategory::where('name', $name)->first();
    }

    /**
     * Get master category names for a given category (comma-separated ready).
     */
    public function getNamesForCategory(int $categoryId): Collection
    {
        return MasterCategory::whereHas('categories', function ($query) use ($categoryId) {
            $query->where('categories.id', $categoryId);
        })->pluck('name');
    }
}