<?php

namespace App\Services;

use App\Exports\OrderLineExport;
use App\Models\ExportProcess;
use App\Models\OrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Service to handle OrderLine exports using chunked batch processing.
 *
 * This service follows the same pattern as HorecaLabelService:
 * 1. Splits order line IDs into chunks
 * 2. Creates an ExportProcess for each chunk
 * 3. Dispatches export jobs as a sequential chain
 *
 * Benefits over OrderLineExportService:
 * - Each job processes only its chunk of IDs (no loading all IDs)
 * - Avoids SQS 256KB message size limit
 * - Better progress tracking with individual ExportProcess per chunk
 * - Sequential processing prevents resource exhaustion
 */
class OrderLineChunkedExportService
{
    /**
     * Number of order line IDs per chunk
     * 1000 IDs keeps the job payload small and query fast
     */
    private const IDS_PER_CHUNK = 1000;

    /**
     * S3 disk name for file storage
     */
    private const S3_DISK = 's3';

    /**
     * Export order lines for the given order IDs.
     *
     * @param Collection $orderIds Collection of order IDs to export
     * @param string|null $description Optional description prefix for export processes
     * @return ExportProcess First export process created (for backward compatibility)
     * @throws \Exception
     */
    public function exportByOrderIds(Collection $orderIds, ?string $description = null): ExportProcess
    {
        $orderLineIds = OrderLine::whereIn('order_id', $orderIds)->pluck('id');

        return $this->export($orderLineIds, $description);
    }

    /**
     * Export order lines by their IDs using chunked batch processing.
     *
     * @param Collection $orderLineIds Collection of order line IDs to export
     * @param string|null $description Optional description prefix for export processes
     * @return ExportProcess First export process created
     * @throws \Exception
     */
    public function export(Collection $orderLineIds, ?string $description = null): ExportProcess
    {
        $totalIds = $orderLineIds->count();

        if ($totalIds === 0) {
            throw new \Exception('No se encontraron líneas de pedido para exportar');
        }

        // Split IDs into chunks
        $idChunks = $orderLineIds->chunk(self::IDS_PER_CHUNK);
        $chunkCount = $idChunks->count();

        Log::info('OrderLineChunkedExportService: Starting chunked export', [
            'total_ids' => $totalIds,
            'chunk_size' => self::IDS_PER_CHUNK,
            'total_chunks' => $chunkCount,
        ]);

        $exportProcesses = [];
        $jobs = [];
        $globalChunkIndex = 0;

        foreach ($idChunks as $chunkIndex => $chunk) {
            $chunkNumber = $chunkIndex + 1;
            $chunkIdCount = $chunk->count();

            // Build description with batch info
            $chunkDescription = $this->buildDescription(
                $description,
                $chunkNumber,
                $chunkCount,
                $chunkIdCount,
                $totalIds
            );

            // Create export process for this chunk with incremental timestamp
            // Add 5 seconds per chunk to ensure proper ordering in UI
            $createdAt = now()->addSeconds($globalChunkIndex * 5);

            $exportProcess = ExportProcess::create([
                'type' => ExportProcess::TYPE_ORDER_LINES,
                'description' => $chunkDescription,
                'status' => ExportProcess::STATUS_QUEUED,
                'file_url' => '-',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Generate file name for this chunk
            $fileName = $this->generateFileName($exportProcess->id, $chunkNumber, $chunkCount);

            // Create closure job that will execute the export
            $jobs[] = $this->createExportJob(
                $chunk->values(),
                $exportProcess->id,
                $fileName
            );

            $exportProcesses[] = $exportProcess;
            $globalChunkIndex++;

            Log::debug('OrderLineChunkedExportService: Created chunk job', [
                'export_process_id' => $exportProcess->id,
                'chunk_number' => $chunkNumber,
                'chunk_id_count' => $chunkIdCount,
                'file_name' => $fileName,
            ]);
        }

        // Dispatch all jobs as a sequential chain
        // Each job will only start after the previous one completes successfully
        Bus::chain($jobs)->dispatch();

        Log::info('OrderLineChunkedExportService: Dispatched job chain', [
            'total_jobs' => count($jobs),
            'first_export_process_id' => $exportProcesses[0]->id,
        ]);

        // Return the first export process (for backward compatibility)
        return $exportProcesses[0];
    }

    /**
     * Build description for an export process chunk.
     *
     * @param string|null $prefix Optional description prefix
     * @param int $chunkNumber Current chunk number (1-based)
     * @param int $totalChunks Total number of chunks
     * @param int $chunkIdCount Number of IDs in this chunk
     * @param int $totalIds Total number of IDs across all chunks
     * @return string
     */
    private function buildDescription(
        ?string $prefix,
        int $chunkNumber,
        int $totalChunks,
        int $chunkIdCount,
        int $totalIds
    ): string {
        $parts = [];

        if ($prefix) {
            $parts[] = $prefix;
        }

        $parts[] = "Lote {$chunkNumber}/{$totalChunks}";
        $parts[] = "{$chunkIdCount} líneas";
        $parts[] = "(Total: {$totalIds})";

        return implode(' - ', $parts);
    }

    /**
     * Generate the export file name.
     *
     * @param int $exportProcessId
     * @param int $chunkNumber
     * @param int $totalChunks
     * @return string
     */
    private function generateFileName(int $exportProcessId, int $chunkNumber, int $totalChunks): string
    {
        $timestamp = now()->format('Ymd_His');
        $dateStr = now()->format('Y/m/d');

        if ($totalChunks > 1) {
            return "exports/order-lines/{$dateStr}/lineas_pedido_{$exportProcessId}_lote{$chunkNumber}de{$totalChunks}_{$timestamp}.xlsx";
        }

        return "exports/order-lines/{$dateStr}/lineas_pedido_{$exportProcessId}_{$timestamp}.xlsx";
    }

    /**
     * Create a closure job for exporting a chunk.
     *
     * The OrderLineExport class handles status updates via its events:
     * - BeforeExport: Sets status to PROCESSING
     * - AfterSheet: Sets status to PROCESSED
     * - failed(): Sets status to PROCESSED_WITH_ERRORS
     *
     * @param Collection $orderLineIds IDs for this chunk
     * @param int $exportProcessId Export process ID
     * @param string $fileName S3 file path
     * @return \Closure
     */
    private function createExportJob(Collection $orderLineIds, int $exportProcessId, string $fileName): \Closure
    {
        return function () use ($orderLineIds, $exportProcessId, $fileName) {
            Log::info('OrderLineChunkedExportService: Processing chunk', [
                'export_process_id' => $exportProcessId,
                'ids_count' => $orderLineIds->count(),
                'file_name' => $fileName,
            ]);

            // Create export with IDs directly in memory (no S3 chunks needed)
            $export = new OrderLineExport(
                $orderLineIds,
                $exportProcessId,
                null, // No S3 base path - using in-memory IDs
                null  // No total chunks
            );

            // Store the export to S3
            // OrderLineExport handles status updates via BeforeExport/AfterSheet events
            Excel::store(
                $export,
                $fileName,
                self::S3_DISK,
                \Maatwebsite\Excel\Excel::XLSX
            );

            // Update file URL after export completes
            $fileUrl = Storage::disk(self::S3_DISK)->url($fileName);
            ExportProcess::where('id', $exportProcessId)->update(['file_url' => $fileUrl]);

            Log::info('OrderLineChunkedExportService: Chunk completed', [
                'export_process_id' => $exportProcessId,
                'file_url' => $fileUrl,
            ]);
        };
    }
}