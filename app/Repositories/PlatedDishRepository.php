<?php

namespace App\Repositories;

use App\Contracts\PlatedDishRepositoryInterface;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\Product;

class PlatedDishRepository implements PlatedDishRepositoryInterface
{
    /**
     * Find a product by its code
     *
     * @param string $productCode
     * @return Product|null
     */
    public function findProductByCode(string $productCode): ?Product
    {
        return Product::where('code', $productCode)->first();
    }

    /**
     * Create or update plated dish for a product
     *
     * @param int $productId
     * @param array $data
     * @return PlatedDish
     */
    public function createOrUpdatePlatedDish(int $productId, array $data): PlatedDish
    {
        return PlatedDish::updateOrCreate(
            ['product_id' => $productId],
            $data
        );
    }

    /**
     * Create or update a plated dish ingredient
     *
     * @param int $platedDishId
     * @param string $ingredientName
     * @param array $data
     * @return PlatedDishIngredient
     */
    public function createOrUpdatePlatedDishIngredient(
        int $platedDishId,
        string $ingredientName,
        array $data
    ): PlatedDishIngredient {
        return PlatedDishIngredient::updateOrCreate(
            [
                'plated_dish_id' => $platedDishId,
                'ingredient_name' => $ingredientName,
            ],
            $data
        );
    }

    /**
     * Get plated dish by product ID
     *
     * @param int $productId
     * @return PlatedDish|null
     */
    public function getPlatedDishByProductId(int $productId): ?PlatedDish
    {
        return PlatedDish::where('product_id', $productId)
            ->with('ingredients')
            ->first();
    }

    /**
     * Delete all ingredients for a plated dish
     *
     * @param int $platedDishId
     * @return void
     */
    public function deleteAllIngredientsForPlatedDish(int $platedDishId): void
    {
        PlatedDishIngredient::where('plated_dish_id', $platedDishId)->delete();
    }

    /**
     * Get plated dishes with ingredients eager loaded
     *
     * @param array $productIds
     * @return \Illuminate\Support\Collection
     */
    public function getPlatedDishesWithIngredients(array $productIds): \Illuminate\Support\Collection
    {
        return PlatedDish::whereIn('product_id', $productIds)
            ->with(['ingredients', 'product'])
            ->get();
    }
}