<?php

namespace App\Services;

use App\Exports\OrderLineExport;
use App\Models\ExportProcess;
use App\Models\Order;
use App\Models\OrderLine;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Service to handle OrderLine exports.
 *
 * Stores IDs in S3 chunks to avoid SQS 256KB payload limit.
 * Uses ChunkAwareQueuedWriter to inject page numbers into export jobs,
 * allowing each job to load only the IDs it needs from S3.
 */
class OrderLineExportService
{
    /**
     * S3 disk name for storing export files
     */
    private string $disk = 's3';

    /**
     * Queue connection to use for exports
     */
    private string $queueConnection = 'sqs';

    /**
     * Chunk size for storing IDs in S3.
     * Must match the chunk_size in config/excel.php
     */
    private int $chunkSize = 1000;

    /**
     * Export order lines for the given order IDs.
     *
     * @param Collection $orderIds Collection of order IDs to export
     * @return ExportProcess The created export process
     */
    public function exportByOrderIds(Collection $orderIds): ExportProcess
    {
        $orderLineIds = OrderLine::whereIn('order_id', $orderIds)->pluck('id');

        // Get date range from orders for description
        $description = $this->getDateRangeDescription($orderIds);

        return $this->export($orderLineIds, $description);
    }

    /**
     * Export order lines by their IDs.
     *
     * Stores IDs in S3 chunks and creates an export that loads only
     * the chunk it needs for each processing job.
     *
     * @param Collection $orderLineIds Collection of order line IDs to export
     * @param string|null $description Optional description for the export process
     * @return ExportProcess The created export process
     */
    public function export(Collection $orderLineIds, ?string $description = null): ExportProcess
    {
        $exportProcess = $this->createExportProcess($description);

        $fileName = $this->generateFileName($exportProcess->id);
        $s3BasePath = $this->generateS3BasePath($exportProcess->id);

        // Store IDs in S3 chunks
        $totalChunks = $this->storeIdsInS3Chunks($orderLineIds, $s3BasePath);

        Log::info('OrderLineExportService: Starting export with S3 chunks', [
            'export_process_id' => $exportProcess->id,
            'total_ids' => $orderLineIds->count(),
            'total_chunks' => $totalChunks,
            's3_base_path' => $s3BasePath,
            'queue_connection' => $this->queueConnection,
        ]);

        // Create export with S3 chunk path (no IDs in memory)
        $export = new OrderLineExport(
            null, // No IDs in memory
            $exportProcess->id,
            $s3BasePath,
            $totalChunks,
            $orderLineIds->count() // Total IDs for querySize()
        );

        // Queue the export
        Excel::store($export, $fileName, $this->disk, \Maatwebsite\Excel\Excel::XLSX)
            ->onConnection($this->queueConnection);

        $fileUrl = Storage::disk($this->disk)->url($fileName);
        $exportProcess->update(['file_url' => $fileUrl]);

        return $exportProcess;
    }

    /**
     * Store IDs in S3 as JSON chunks.
     *
     * @param Collection $ids Collection of IDs to store
     * @param string $basePath Base path for chunk files (without -chunk-N.json suffix)
     * @return int Total number of chunks created
     */
    private function storeIdsInS3Chunks(Collection $ids, string $basePath): int
    {
        $chunks = $ids->chunk($this->chunkSize);
        $totalChunks = $chunks->count();

        foreach ($chunks as $index => $chunk) {
            $chunkPath = "{$basePath}-chunk-{$index}.json";
            $json = json_encode($chunk->values()->toArray());

            Storage::disk($this->disk)->put($chunkPath, $json);

            Log::debug('OrderLineExportService: Stored chunk in S3', [
                'chunk_index' => $index,
                'chunk_path' => $chunkPath,
                'ids_count' => $chunk->count(),
            ]);
        }

        return $totalChunks;
    }

    /**
     * Generate S3 base path for chunk files.
     *
     * @param int $exportProcessId
     * @return string Base path (without -chunk-N.json suffix)
     */
    private function generateS3BasePath(int $exportProcessId): string
    {
        $timestamp = time();
        return "exports/order-lines/chunks/export_{$exportProcessId}_{$timestamp}";
    }

    /**
     * Create a new export process record.
     *
     * @param string|null $description Optional description for the export
     * @return ExportProcess
     */
    private function createExportProcess(?string $description = null): ExportProcess
    {
        return ExportProcess::create([
            'type' => ExportProcess::TYPE_ORDER_LINES,
            'status' => ExportProcess::STATUS_QUEUED,
            'description' => $description,
            'file_url' => '-',
        ]);
    }

    /**
     * Get a description with the date range from the given order IDs.
     *
     * @param Collection $orderIds Collection of order IDs
     * @return string Description with date range (e.g., "Órdenes del 01/12/2025 al 15/12/2025")
     */
    private function getDateRangeDescription(Collection $orderIds): string
    {
        $dateRange = Order::whereIn('id', $orderIds)
            ->selectRaw('MIN(created_at) as min_date, MAX(created_at) as max_date')
            ->first();

        if (!$dateRange || !$dateRange->min_date) {
            return 'Exportación de líneas de pedido';
        }

        $minDate = Carbon::parse($dateRange->min_date)->format('d/m/Y');
        $maxDate = Carbon::parse($dateRange->max_date)->format('d/m/Y');

        if ($minDate === $maxDate) {
            return "Órdenes del {$minDate}";
        }

        return "Órdenes del {$minDate} al {$maxDate}";
    }

    /**
     * Generate the export file name.
     *
     * @param int $exportProcessId
     * @return string
     */
    private function generateFileName(int $exportProcessId): string
    {
        $timestamp = time();
        return "exports/order-lines/lineas_pedido_export_{$exportProcessId}_{$timestamp}.xlsx";
    }

    /**
     * Set the queue connection to use.
     *
     * @param string $connection
     * @return self
     */
    public function setQueueConnection(string $connection): self
    {
        $this->queueConnection = $connection;
        return $this;
    }

    /**
     * Set the chunk size for S3 storage.
     *
     * @param int $size
     * @return self
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;
        return $this;
    }
}