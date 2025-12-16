<?php

namespace App\Services\Labels;

use App\Contracts\NutritionalLabelDataPreparerInterface;
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
        protected NutritionalInformationRepository $repository,
        protected NutritionalLabelDataPreparerInterface $dataPreparer
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

        // NEW: Use helper to prepare all data (validation, grouping, chunking)
        $preparedData = $this->dataPreparer->prepareData($productIds, $quantities, self::LABELS_PER_CHUNK);

        // Create export processes and jobs for each chunk
        $exportProcesses = [];
        $jobs = [];

        foreach ($preparedData['chunks'] as $index => $chunk) {
            // Build description
            $description = "Etiquetas nutricionales: {$chunk['area_name']} - Lote {$chunk['chunk_number']}/{$chunk['total_chunks_in_area']} ({$chunk['label_count']} etiqueta(s), productos #{$chunk['first_product_id']} a #{$chunk['last_product_id']})";
            if ($productionOrderCode) {
                $description .= " - Orden de Producción: {$productionOrderCode}";
            }

            // Create export process with incremental timestamp for ordering
            $createdAt = now()->addSeconds($index * 5);

            $exportProcess = ExportProcess::create([
                'type' => ExportProcess::TYPE_NUTRITIONAL_INFORMATION,
                'description' => $description,
                'status' => ExportProcess::STATUS_QUEUED,
                'file_url' => '-',
                'created_at' => $createdAt,
                'updated_at' => $createdAt
            ]);

            // Create job for this chunk
            $jobs[] = new GenerateNutritionalLabelsJob(
                $chunk['product_ids'],
                $elaborationDate,
                $exportProcess->id,
                $chunk['quantities'],
                $chunk['area_name'],
                $productionOrderCode,
                $chunk['product_start_indexes']
            );

            $exportProcesses[] = $exportProcess;
        }

        // Dispatch all jobs as a sequential chain
        Bus::chain($jobs)->dispatch();

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