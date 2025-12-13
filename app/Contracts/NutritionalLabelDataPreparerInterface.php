<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface NutritionalLabelDataPreparerInterface
{
    /**
     * Prepare label data grouped by production area and chunked for processing
     *
     * This method:
     * 1. Validates products have nutritional information and label generation enabled
     * 2. Filters out HORECA products
     * 3. Groups products by production area
     * 4. Expands products based on quantities
     * 5. Creates chunks for batch processing
     *
     * @param array $productIds Array of product IDs
     * @param array $quantities Array with structure [product_id => quantity]. If empty, 1 label per product.
     * @param int $chunkSize Maximum labels per chunk (default 100)
     * @return array{
     *   chunks: array<int, array{
     *     area_name: string,
     *     product_ids: array<int>,
     *     quantities: array<int, int>,
     *     chunk_number: int,
     *     total_chunks_in_area: int,
     *     label_count: int,
     *     first_product_id: int,
     *     last_product_id: int
     *   }>,
     *   total_labels: int,
     *   valid_product_ids: array,
     *   not_found_ids: array
     * }
     * @throws \Exception If no valid products found
     */
    public function prepareData(array $productIds, array $quantities = [], int $chunkSize = 100): array;

    /**
     * Get valid products for label generation (without expanding by quantities)
     *
     * @param array $productIds
     * @return Collection
     */
    public function getValidProducts(array $productIds): Collection;

    /**
     * Get expanded products for a specific set of product IDs and quantities
     * Used by sync processing (command) to get actual product models
     *
     * Each product in the returned collection will have a 'label_index' attribute
     * representing its sequential number starting from $startIndex
     *
     * @param array $productIds
     * @param array $quantities
     * @param int $startIndex Starting index for label numbering (default 1)
     * @return Collection
     */
    public function getExpandedProducts(array $productIds, array $quantities, int $startIndex = 1): Collection;
}