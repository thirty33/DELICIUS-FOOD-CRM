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

            // Track per-product label counters across chunks within this area
            // This ensures products split across chunks continue their sequence
            $productLabelCounters = [];

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

                // Calculate start_index for each product in this chunk
                // Products that were in previous chunks continue from where they left off
                // New products start at 1
                $productStartIndexes = [];
                foreach (array_keys($chunkQuantities) as $productId) {
                    $productStartIndexes[$productId] = $productLabelCounters[$productId] ?? 1;
                    // Update counter for next chunk
                    $productLabelCounters[$productId] = ($productLabelCounters[$productId] ?? 1) + $chunkQuantities[$productId];
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
                    'start_index' => 1, // Deprecated: kept for backwards compatibility
                    'product_start_indexes' => $productStartIndexes, // Per-product start indexes
                ];
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
     *
     * @param array $productIds Product IDs to expand
     * @param array $quantities Quantities per product [product_id => quantity]
     * @param int|array $startIndex Either a single start index (deprecated) or array of per-product start indexes
     * @return Collection
     */
    public function getExpandedProducts(array $productIds, array $quantities, int|array $startIndex = 1): Collection
    {
        $products = $this->repository->getProductsForLabelGeneration($productIds, $quantities);

        // Support both old (single int) and new (per-product array) start index formats
        $productStartIndexes = is_array($startIndex) ? $startIndex : [];
        $usePerProductIndexes = !empty($productStartIndexes);

        // Track per-product counters
        $productCounters = [];

        foreach ($products as $product) {
            $productId = $product->id;

            // Initialize counter for this product if not set
            if (!isset($productCounters[$productId])) {
                if ($usePerProductIndexes && isset($productStartIndexes[$productId])) {
                    // Use the pre-calculated start index for this product
                    $productCounters[$productId] = $productStartIndexes[$productId];
                } else {
                    // New product starts at 1
                    $productCounters[$productId] = 1;
                }
            }

            $product->label_index = $productCounters[$productId];
            $productCounters[$productId]++;
        }

        return $products;
    }
}