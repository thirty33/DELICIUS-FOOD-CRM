<?php

namespace App\Repositories;

use App\Contracts\NutritionalInformationRepositoryInterface;
use App\Enums\NutritionalValueType;
use App\Models\NutritionalInformation;
use App\Models\NutritionalValue;
use App\Models\Product;

class NutritionalInformationRepository implements NutritionalInformationRepositoryInterface
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
     * Create or update nutritional information for a product
     *
     * @param int $productId
     * @param array $data
     * @return NutritionalInformation
     */
    public function createOrUpdateNutritionalInformation(int $productId, array $data): NutritionalInformation
    {
        return NutritionalInformation::updateOrCreate(
            ['product_id' => $productId],
            $data
        );
    }

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
    ): NutritionalValue {
        return NutritionalValue::updateOrCreate(
            [
                'nutritional_information_id' => $nutritionalInformationId,
                'type' => $type->value,
            ],
            [
                'value' => $value,
            ]
        );
    }

    /**
     * Create or update multiple nutritional values at once
     *
     * @param int $nutritionalInformationId
     * @param array $values Array of ['type' => value]
     * @return void
     */
    public function createOrUpdateNutritionalValues(int $nutritionalInformationId, array $values): void
    {
        foreach ($values as $type => $value) {
            // Convert string type to enum if needed
            $typeEnum = $type instanceof NutritionalValueType
                ? $type
                : NutritionalValueType::from($type);

            $this->createOrUpdateNutritionalValue($nutritionalInformationId, $typeEnum, (float) $value);
        }
    }

    /**
     * Get nutritional information by product ID
     *
     * @param int $productId
     * @return NutritionalInformation|null
     */
    public function getNutritionalInformationByProductId(int $productId): ?NutritionalInformation
    {
        return NutritionalInformation::where('product_id', $productId)
            ->with('nutritionalValues')
            ->first();
    }
}