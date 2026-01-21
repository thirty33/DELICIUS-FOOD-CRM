<?php

namespace App\Repositories;

use App\Models\Category;

class CategoryRepository
{
    /**
     * Get the dynamic category for best-selling products.
     *
     * @return Category|null
     */
    public function getDynamicCategory(): ?Category
    {
        return Category::where('is_dynamic', true)->first();
    }

    /**
     * Find a category by ID.
     *
     * @param int $categoryId
     * @return Category|null
     */
    public function find(int $categoryId): ?Category
    {
        return Category::find($categoryId);
    }
}
