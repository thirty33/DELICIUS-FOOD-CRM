<?php

namespace App\Contracts;

interface HorecaInformationRepositoryInterface
{
    /**
     * Get barcode number for label
     *
     * @param mixed $product
     * @return string|null
     */
    public function getBarcode($product): ?string;

    /**
     * Get expiration date based on elaboration date
     *
     * @param mixed $product
     * @param string $elaborationDate
     * @return string
     */
    public function getExpirationDate($product, string $elaborationDate): string;

    /**
     * Get ingredients list for label
     *
     * @param mixed $product
     * @return string
     */
    public function getIngredients($product): string;

    /**
     * Get allergens list for label
     *
     * @param mixed $product
     * @return string
     */
    public function getAllergens($product): string;

    /**
     * Get gross weight formatted for label
     *
     * @param mixed $product
     * @return string
     */
    public function getGrossWeight($product): string;

    /**
     * Get portion weight formatted for label
     *
     * @param mixed $product
     * @return string
     */
    public function getPortionWeight($product): string;

    /**
     * Get nutritional values formatted for label
     *
     * @param mixed $product
     * @return array
     */
    public function getNutritionalValuesForLabel($product): array;

    /**
     * Get active high content flags (alto en...)
     *
     * @param mixed $product
     * @return array
     */
    public function getActiveHighContentFlags($product): array;

    /**
     * Get products for label generation
     *
     * @param array $productIds
     * @param array $quantities
     * @return \Illuminate\Support\Collection
     */
    public function getProductsForLabelGeneration(array $productIds, array $quantities = []): \Illuminate\Support\Collection;
}