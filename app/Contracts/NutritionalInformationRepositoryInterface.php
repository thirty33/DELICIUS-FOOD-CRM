<?php

namespace App\Contracts;

use App\Enums\NutritionalValueType;
use App\Models\NutritionalInformation;
use App\Models\NutritionalValue;
use App\Models\Product;

interface NutritionalInformationRepositoryInterface
{
    /**
     * Find a product by its code
     *
     * @param string $productCode
     * @return Product|null
     */
    public function findProductByCode(string $productCode): ?Product;

    /**
     * Create or update nutritional information for a product
     *
     * @param int $productId
     * @param array $data
     * @return NutritionalInformation
     */
    public function createOrUpdateNutritionalInformation(int $productId, array $data): NutritionalInformation;

    /**
     * Create or update a nutritional value
     *
     * @param int $nutritionalInformationId
     * @param NutritionalValueType $type
     * @param float $value
     * @return NutritionalValue
     */
    public function createOrUpdateNutritionalValue(
        int $nutritionalInformationId,
        NutritionalValueType $type,
        float $value
    ): NutritionalValue;

    /**
     * Create or update multiple nutritional values at once
     *
     * @param int $nutritionalInformationId
     * @param array $values Array of ['type' => value]
     * @return void
     */
    public function createOrUpdateNutritionalValues(int $nutritionalInformationId, array $values): void;

    /**
     * Get nutritional information by product ID
     *
     * @param int $productId
     * @return NutritionalInformation|null
     */
    public function getNutritionalInformationByProductId(int $productId): ?NutritionalInformation;
}