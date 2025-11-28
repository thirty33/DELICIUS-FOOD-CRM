<?php

namespace App\Services\Labels;

use App\Jobs\GenerateNutritionalLabelsJob;
use App\Models\ExportProcess;
use App\Models\Product;
use App\Repositories\NutritionalInformationRepository;
use Illuminate\Support\Facades\Bus;

/**
 * Service for generating nutritional labels
 *
 * Orchestrates the label generation process:
 * 1. Fetches products with nutritional information
 * 2. Creates export process record
 * 3. Dispatches job for async processing
 */
class NutritionalLabelService
{
    /**
     * Maximum number of labels per chunk for batch processing
     * Adjust this value based on server memory and performance requirements
     *
     * @var int
     */
    private const LABELS_PER_CHUNK = 100;

    public function __construct(
        protected NutritionalInformationRepository $repository
    ) {}

    /**
     * Generate nutritional labels for given product IDs
     *
     * @param array $productIds Array of product IDs
     * @param string|null $elaborationDate Elaboration date in d/m/Y format
     * @param array $quantities Array with structure [product_id => quantity]. If empty, generates one label per product.
     * @param string|null $productionOrderCode Optional production order code for description
     * @return ExportProcess
     * @throws \Exception
     */
    public function generateLabels(array $productIds, ?string $elaborationDate = null, array $quantities = [], ?string $productionOrderCode = null): ExportProcess
    {
        $elaborationDate = $elaborationDate ?: now()->format('d/m/Y');

        // Step 1: Validate products before creating chunks
        // Fetch UNIQUE products to ensure they exist and have nutritional information
        // Do NOT expand by quantities yet - we need unique products for grouping
        $validationProducts = $this->repository->getProductsForLabelGeneration($productIds, []);

        if ($validationProducts->isEmpty()) {
            throw new \Exception('No se encontraron productos con información nutricional y etiqueta habilitada');
        }

        // Step 2: Get valid product IDs from validated products
        // Only create chunks for products that actually have nutritional information
        $validProductIds = $validationProducts->pluck('id')->unique()->toArray();

        // Step 3: Group UNIQUE products by production area
        // Each product should belong to one production area (or "SIN CUARTO PRODUCTIVO")
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

        // Step 4: Expand product IDs by quantities within each area and create chunks
        // Jobs will re-fetch products from DB (avoids serialization memory issues)
        $exportProcesses = [];
        $jobs = [];
        $globalChunkIndex = 0;
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
            $areaChunks = array_chunk($expandedAreaProductIds, self::LABELS_PER_CHUNK);
            $areaChunksCount = count($areaChunks);

            foreach ($areaChunks as $areaChunkIndex => $chunkProductIds) {
                $areaChunkNumber = $areaChunkIndex + 1;
                $chunkLabelCount = count($chunkProductIds);

                // Get unique product IDs in this chunk for description
                $uniqueChunkProductIds = collect($chunkProductIds)->unique()->sort()->values();
                $firstProduct = $uniqueChunkProductIds->first();
                $lastProduct = $uniqueChunkProductIds->last();

                // Build description with production area and optional production order code
                $description = "Etiquetas nutricionales: {$areaName} - Lote {$areaChunkNumber}/{$areaChunksCount} ({$chunkLabelCount} etiqueta(s), productos #{$firstProduct} a #{$lastProduct})";
                if ($productionOrderCode) {
                    $description .= " - Orden de Producción: {$productionOrderCode}";
                }

                // Create export process for this chunk with incremental timestamp
                // Add 5 seconds per chunk to created_at to ensure proper ordering in the UI
                // (5 seconds ensures visible ordering even with DESC sort)
                $createdAt = now()->addSeconds($globalChunkIndex * 5);

                $exportProcess = ExportProcess::create([
                    'type' => ExportProcess::TYPE_NUTRITIONAL_INFORMATION,
                    'description' => $description,
                    'status' => ExportProcess::STATUS_QUEUED,
                    'file_url' => '-',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt
                ]);

                // Build quantities array for this chunk
                $chunkQuantities = [];
                foreach ($chunkProductIds as $productId) {
                    $chunkQuantities[$productId] = ($chunkQuantities[$productId] ?? 0) + 1;
                }

                // Create job instance passing IDs, quantities, and metadata
                // Job will fetch products from DB using repository
                $jobs[] = new GenerateNutritionalLabelsJob(
                    array_keys($chunkQuantities),
                    $elaborationDate,
                    $exportProcess->id,
                    $chunkQuantities,
                    $areaName,
                    $productionOrderCode
                );

                $exportProcesses[] = $exportProcess;

                $globalChunkIndex++;
            }
        }

        // Step 4: Dispatch all jobs as a sequential chain
        // Each job will only start after the previous one completes successfully
        Bus::chain($jobs)->dispatch();

        // Return the first export process (for backward compatibility)
        // Frontend will receive the first process, others will be processed in background
        return $exportProcesses[0];
    }

    /**
     * Create export process record for labels
     *
     * @param array $productIds
     * @param int $totalLabels
     * @return ExportProcess
     */
    protected function createExportProcessForLabels(array $productIds, int $totalLabels): ExportProcess
    {
        $uniqueProductIds = collect($productIds)->unique()->sort()->values();
        $uniqueProductCount = $uniqueProductIds->count();
        $firstProduct = $uniqueProductIds->first();
        $lastProduct = $uniqueProductIds->last();

        if ($totalLabels === 1) {
            $description = "Etiqueta nutricional del producto #{$firstProduct}";
        } elseif ($uniqueProductCount === 1) {
            $description = "Etiquetas nutricionales: {$totalLabels} etiqueta(s) del producto #{$firstProduct}";
        } else {
            $description = "Etiquetas nutricionales: {$totalLabels} etiqueta(s) de {$uniqueProductCount} productos (#{$firstProduct} a #{$lastProduct})";
        }

        return ExportProcess::create([
            'type' => ExportProcess::TYPE_NUTRITIONAL_INFORMATION,
            'description' => $description,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-'
        ]);
    }
}