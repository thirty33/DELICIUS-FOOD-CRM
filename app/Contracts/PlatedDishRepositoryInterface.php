<?php

namespace App\Contracts;

use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\Product;

interface PlatedDishRepositoryInterface
{
    /**
     * Find a product by its code
     *
     * @param string $productCode
     * @return Product|null
     */
    public function findProductByCode(string $productCode): ?Product;

    /**
     * Create or update plated dish for a product
     *
     * @param int $productId
     * @param array $data
     * @return PlatedDish
     */
    public function createOrUpdatePlatedDish(int $productId, array $data): PlatedDish;

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
    ): PlatedDishIngredient;

    /**
     * Get plated dish by product ID
     *
     * @param int $productId
     * @return PlatedDish|null
     */
    public function getPlatedDishByProductId(int $productId): ?PlatedDish;

    /**
     * Delete all ingredients for a plated dish
     *
     * @param int $platedDishId
     * @return void
     */
    public function deleteAllIngredientsForPlatedDish(int $platedDishId): void;

    /**
     * Get plated dishes with ingredients eager loaded
     *
     * @param array $productIds
     * @return \Illuminate\Support\Collection
     */
    public function getPlatedDishesWithIngredients(array $productIds): \Illuminate\Support\Collection;
}