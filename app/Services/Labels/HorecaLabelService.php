<?php

namespace App\Services\Labels;

use App\Contracts\HorecaLabelDataRepositoryInterface;
use App\Jobs\GenerateHorecaLabelsJob;
use App\Models\ExportProcess;
use App\Repositories\AdvanceOrderRepository;
use Illuminate\Support\Facades\Bus;

/**
 * Service for generating HORECA ingredient labels
 *
 * Orchestrates HORECA label generation process:
 * 1. Fetches order IDs from advance order
 * 2. Fetches HORECA label data (ingredients with weights)
 * 3. Expands labels with individual weights
 * 4. Creates export process records
 * 5. Dispatches jobs for async processing
 */
class HorecaLabelService
{
    /**
     * Maximum number of labels per chunk for batch processing
     * HORECA labels are simpler so we can process more per chunk
     *
     * @var int
     */
    private const LABELS_PER_CHUNK = 150;

    public function __construct(
        protected HorecaLabelDataRepositoryInterface $horecaRepository,
        protected AdvanceOrderRepository $advanceOrderRepository
    ) {}

    /**
     * Generate HORECA labels for an advance order
     *
     * @param int $advanceOrderId Advance order ID
     * @param string|null $elaborationDate Elaboration date in d/m/Y format
     * @return ExportProcess First export process created
     * @throws \Exception
     */
    public function generateLabelsForAdvanceOrder(int $advanceOrderId, ?string $elaborationDate = null): ExportProcess
    {
        $elaborationDate = $elaborationDate ?: now()->format('d/m/Y');

        // Step 1: Get HORECA label data directly from AdvanceOrder
        // This method uses associatedOrderLines which respects the production area filter
        // applied when creating the AdvanceOrder (fixes bug where labels included ALL
        // order lines from orders instead of only those in the OP)
        $labelData = $this->horecaRepository->getHorecaLabelDataByAdvanceOrder($advanceOrderId);

        if ($labelData->isEmpty()) {
            throw new \Exception('No se encontraron ingredientes HORECA para las órdenes del Advance Order');
        }

        // Step 3: Expand labels with weights (each weight = one label)
        // This logic is tested in HorecaLabelDataRepositoryTest
        $expandedLabels = $this->expandLabelsWithWeights($labelData);

        $totalLabels = $expandedLabels->count();

        if ($totalLabels === 0) {
            throw new \Exception('No se generaron etiquetas después de expandir los pesos');
        }

        // Step 4: Create chunks and dispatch jobs
        $labelChunks = $expandedLabels->chunk(self::LABELS_PER_CHUNK);
        $chunkCount = $labelChunks->count();

        $exportProcesses = [];
        $jobs = [];
        $globalChunkIndex = 0;

        foreach ($labelChunks as $chunkIndex => $chunk) {
            $chunkNumber = $chunkIndex + 1;
            $chunkLabelCount = $chunk->count();

            // Build description
            $description = "Etiquetas HORECA: OP #{$advanceOrderId} - Lote {$chunkNumber}/{$chunkCount} ({$chunkLabelCount} etiqueta(s))";

            // Create export process for this chunk with incremental timestamp
            // Add 5 seconds per chunk to ensure proper ordering in UI
            $createdAt = now()->addSeconds($globalChunkIndex * 5);

            $exportProcess = ExportProcess::create([
                'type' => ExportProcess::TYPE_NUTRITIONAL_INFORMATION,
                'description' => $description,
                'status' => ExportProcess::STATUS_QUEUED,
                'file_url' => '-',
                'created_at' => $createdAt,
                'updated_at' => $createdAt
            ]);

            // Create job instance passing label data as array
            $jobs[] = new GenerateHorecaLabelsJob(
                $chunk->toArray(),
                $elaborationDate,
                $exportProcess->id,
                $advanceOrderId
            );

            $exportProcesses[] = $exportProcess;
            $globalChunkIndex++;
        }

        // Step 5: Dispatch all jobs as a sequential chain
        // Each job will only start after the previous one completes successfully
        Bus::chain($jobs)->dispatch();

        // Return the first export process (for backward compatibility)
        return $exportProcesses[0];
    }

    /**
     * Expand label data with individual weights
     *
     * Converts grouped label data (with weights array) into individual label entries.
     * Each weight becomes a separate label with all other data duplicated.
     *
     * Logic tested in: tests/Unit/Repositories/HorecaLabelDataRepositoryTest.php
     *
     * @param \Illuminate\Support\Collection $labelData Grouped label data with weights arrays
     * @return \Illuminate\Support\Collection Expanded label data (one entry per weight)
     */
    protected function expandLabelsWithWeights(\Illuminate\Support\Collection $labelData): \Illuminate\Support\Collection
    {
        $expanded = collect();

        foreach ($labelData as $item) {
            // Each weight in the weights array becomes a separate label
            foreach ($item['weights'] as $weight) {
                $expanded->push([
                    'ingredient_name' => $item['ingredient_name'],
                    'ingredient_product_code' => $item['ingredient_product_code'],
                    'grouper_name' => $item['grouper_name'] ?? $item['branch_fantasy_name'],
                    // Keep branch_fantasy_name for backward compatibility
                    'branch_fantasy_name' => $item['branch_fantasy_name'],
                    'product_id' => $item['product_id'] ?? null,
                    'product_name' => $item['product_name'] ?? null,
                    'measure_unit' => $item['measure_unit'],
                    'net_weight' => $weight,
                ]);
            }
        }

        return $expanded;
    }
}