<?php

namespace App\Services\Labels;

use App\Jobs\GenerateNutritionalLabelsJob;
use App\Models\ExportProcess;
use App\Models\Product;
use App\Repositories\NutritionalInformationRepository;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        protected NutritionalInformationRepository $repository
    ) {}

    /**
     * Generate nutritional labels for given product IDs
     *
     * @param array $productIds Array of product IDs
     * @param string|null $elaborationDate Elaboration date in d/m/Y format
     * @param array $quantities Array with structure [product_id => quantity]. If empty, generates one label per product.
     * @return ExportProcess
     * @throws \Exception
     */
    public function generateLabels(array $productIds, ?string $elaborationDate = null, array $quantities = []): ExportProcess
    {
        // Step 1: Create export process
        $totalLabels = empty($quantities) ? count($productIds) : array_sum($quantities);
        $exportProcess = $this->createExportProcessForLabels($productIds, $totalLabels);

        // Step 2: Dispatch job for async processing
        // Job will fetch products using repository with quantities
        GenerateNutritionalLabelsJob::dispatch(
            $productIds,
            $elaborationDate ?: now()->format('d/m/Y'),
            $exportProcess->id,
            $quantities
        );

        Log::info('Nutritional labels generation initiated', [
            'export_process_id' => $exportProcess->id,
            'product_ids' => $productIds,
            'total_labels' => $totalLabels,
            'elaboration_date' => $elaborationDate,
            'quantities' => $quantities
        ]);

        return $exportProcess;
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