<?php

namespace App\Services\Labels;

use App\Contracts\NutritionalLabelDataPreparerInterface;
use App\Repositories\NutritionalInformationRepository;
use Illuminate\Support\Collection;

/**
 * Prepares data for nutritional label generation
 *
 * This class encapsulates ALL the logic for:
 * - Validating products have nutritional information
 * - Filtering out HORECA products
 * - Grouping products by production area
 * - Expanding products based on quantities
 * - Creating chunks for batch processing
 *
 * Used by both NutritionalLabelService (async/queued) and GenerateProductLabels command (sync)
 */
class NutritionalLabelDataPreparer implements NutritionalLabelDataPreparerInterface
{
    public function __construct(
        protected NutritionalInformationRepository $repository
    ) {}

    /**
     * {@inheritdoc}
     */
    public function prepareData(array $productIds, array $quantities = [], int $chunkSize = 100): array
    {
        // Step 1: Validate products - fetch UNIQUE products to ensure they exist
        // and have nutritional information enabled
        $validationProducts = $this->repository->getProductsForLabelGeneration($productIds, []);

        if ($validationProducts->isEmpty()) {
            throw new \Exception('No se encontraron productos con información nutricional y etiqueta habilitada');
        }

        // Step 2: Get valid product IDs from validated products
        $validProductIds = $validationProducts->pluck('id')->unique()->toArray();

        // Step 3: Identify not found IDs
        $notFoundIds = array_diff($productIds, $validProductIds);

        // Step 4: Group UNIQUE products by production area
        $productsByArea = [];
        foreach ($validationProducts as $product) {
            $areaName = $product->productionAreas->first()?->name ?? 'SIN CUARTO PRODUCTIVO';

            if (!isset($productsByArea[$areaName])) {
                $productsByArea[$areaName] = [];
            }

            // Store unique product IDs only (no duplicates)
            if (!in_array($product->id, $productsByArea[$areaName])) {
                $productsByArea[$areaName][] = $product->id;
            }
        }

        // Sort areas alphabetically for consistent ordering
        ksort($productsByArea);

        // Step 5: Expand product IDs by quantities within each area and create chunks
        $chunks = [];
        $totalLabels = 0;
        $globalLabelIndex = 1; // Start index for label counter (continues across chunks)

        foreach ($productsByArea as $areaName => $areaProductIds) {
            // Expand product IDs based on quantities for this area
            $expandedAreaProductIds = [];
            foreach ($areaProductIds as $productId) {
                $quantity = $quantities[$productId] ?? 1;
                for ($i = 0; $i < $quantity; $i++) {
                    $expandedAreaProductIds[] = $productId;
                }
            }

            $areaLabelCount = count($expandedAreaProductIds);
            $totalLabels += $areaLabelCount;

            // Chunk this area's products
            $areaChunks = array_chunk($expandedAreaProductIds, $chunkSize);
            $areaChunksCount = count($areaChunks);

            foreach ($areaChunks as $areaChunkIndex => $chunkProductIds) {
                $areaChunkNumber = $areaChunkIndex + 1;
                $chunkLabelCount = count($chunkProductIds);

                // Get unique product IDs in this chunk
                $uniqueChunkProductIds = collect($chunkProductIds)->unique()->sort()->values();
                $firstProduct = $uniqueChunkProductIds->first();
                $lastProduct = $uniqueChunkProductIds->last();

                // Build quantities array for this chunk
                $chunkQuantities = [];
                foreach ($chunkProductIds as $productId) {
                    $chunkQuantities[$productId] = ($chunkQuantities[$productId] ?? 0) + 1;
                }

                $chunks[] = [
                    'area_name' => $areaName,
                    'product_ids' => array_keys($chunkQuantities),
                    'quantities' => $chunkQuantities,
                    'chunk_number' => $areaChunkNumber,
                    'total_chunks_in_area' => $areaChunksCount,
                    'label_count' => $chunkLabelCount,
                    'first_product_id' => $firstProduct,
                    'last_product_id' => $lastProduct,
                    'start_index' => $globalLabelIndex, // Starting label number for this chunk
                ];

                // Increment global index for next chunk
                $globalLabelIndex += $chunkLabelCount;
            }
        }

        return [
            'chunks' => $chunks,
            'total_labels' => $totalLabels,
            'valid_product_ids' => $validProductIds,
            'not_found_ids' => array_values($notFoundIds),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getValidProducts(array $productIds): Collection
    {
        return $this->repository->getProductsForLabelGeneration($productIds, []);
    }

    /**
     * {@inheritdoc}
     */
    public function getExpandedProducts(array $productIds, array $quantities, int $startIndex = 1): Collection
    {
        $products = $this->repository->getProductsForLabelGeneration($productIds, $quantities);

        // Add label_index per product (resets to 1 for each new product)
        $currentIndex = 1;
        $lastProductId = null;

        foreach ($products as $product) {
            if ($product->id !== $lastProductId) {
                // New product, reset index to 1
                $currentIndex = 1;
                $lastProductId = $product->id;
            }
            $product->label_index = $currentIndex;
            $currentIndex++;
        }

        return $products;
    }
}