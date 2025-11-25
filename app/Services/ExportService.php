<?php

namespace App\Services;

use App\Models\ExportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Generic Export Service
 *
 * Handles Excel export operations with standardized error handling and process tracking.
 * Decouples export logic from specific exporter classes and contexts.
 */
class ExportService
{
    /**
     * Execute an export operation
     *
     * @param string $exporterClass Fully qualified exporter class name
     * @param Collection $recordIds IDs of records to export
     * @param string $exportType Type of export (use ExportProcess::TYPE_* constants)
     * @param string $fileName File name/path for the export
     * @param array $exporterArguments Additional arguments to pass to the exporter constructor
     * @param string|null $disk Storage disk (default: null for local, 's3' for S3)
     * @return ExportProcess The export process record with updated status and file URL
     */
    public function export(
        string $exporterClass,
        Collection $recordIds,
        string $exportType,
        string $fileName,
        array $exporterArguments = [],
        ?string $disk = null
    ): ExportProcess {
        // Create export process record
        $exportProcess = ExportProcess::create([
            'type' => $exportType,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-'
        ]);

        try {
            // Add export process ID as last argument for the exporter
            $arguments = array_merge([$recordIds], $exporterArguments, [$exportProcess->id]);

            // Create exporter instance with provided arguments
            $exporter = new $exporterClass(...$arguments);

            // Execute export
            if ($disk) {
                Excel::store($exporter, $fileName, $disk, \Maatwebsite\Excel\Excel::XLSX);
            } else {
                Excel::store($exporter, $fileName);
            }

            // Update file URL if using cloud storage
            if ($disk) {
                $fileUrl = Storage::disk($disk)->url($fileName);
                $exportProcess->update([
                    'file_url' => $fileUrl
                ]);
            } else {
                $exportProcess->update([
                    'file_url' => $fileName
                ]);
            }

            Log::info('Export completed successfully', [
                'export_process_id' => $exportProcess->id,
                'type' => $exportType,
                'file' => $fileName,
            ]);
        } catch (\Exception $e) {
            Log::error('Export failed with exception', [
                'export_process_id' => $exportProcess->id,
                'type' => $exportType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update process status
            $exportProcess->refresh();
            $exportProcess->update([
                'status' => ExportProcess::STATUS_PROCESSED_WITH_ERRORS,
                'error_log' => array_merge($exportProcess->error_log ?? [], [[
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]]),
            ]);
        }

        return $exportProcess->refresh();
    }

    /**
     * Execute export and return raw file content (for tests)
     *
     * @param string $exporterClass Fully qualified exporter class name
     * @param Collection $recordIds IDs of records to export
     * @param string $exportType Type of export (use ExportProcess::TYPE_* constants)
     * @param array $exporterArguments Additional arguments to pass to the exporter constructor
     * @return array ['content' => raw content, 'exportProcess' => ExportProcess]
     */
    public function exportRaw(
        string $exporterClass,
        Collection $recordIds,
        string $exportType,
        array $exporterArguments = []
    ): array {
        // Create export process record
        $exportProcess = ExportProcess::create([
            'type' => $exportType,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-'
        ]);

        try {
            // Add export process ID as last argument for the exporter
            $arguments = array_merge([$recordIds], $exporterArguments, [$exportProcess->id]);

            // Create exporter instance with provided arguments
            $exporter = new $exporterClass(...$arguments);

            // Generate raw content (bypass queuing)
            $content = Excel::raw($exporter, \Maatwebsite\Excel\Excel::XLSX);

            $exportProcess->update([
                'status' => ExportProcess::STATUS_PROCESSED,
            ]);

            Log::info('Export raw completed successfully', [
                'export_process_id' => $exportProcess->id,
                'type' => $exportType,
            ]);

            return [
                'content' => $content,
                'exportProcess' => $exportProcess->refresh()
            ];
        } catch (\Exception $e) {
            Log::error('Export raw failed with exception', [
                'export_process_id' => $exportProcess->id,
                'type' => $exportType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update process status
            $exportProcess->refresh();
            $exportProcess->update([
                'status' => ExportProcess::STATUS_PROCESSED_WITH_ERRORS,
                'error_log' => array_merge($exportProcess->error_log ?? [], [[
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]]),
            ]);

            throw $e;
        }
    }

    /**
     * Check if export completed successfully
     *
     * @param ExportProcess $exportProcess
     * @return bool
     */
    public function wasSuccessful(ExportProcess $exportProcess): bool
    {
        return $exportProcess->status === ExportProcess::STATUS_PROCESSED;
    }

    /**
     * Check if export completed with errors
     *
     * @param ExportProcess $exportProcess
     * @return bool
     */
    public function hasErrors(ExportProcess $exportProcess): bool
    {
        return $exportProcess->status === ExportProcess::STATUS_PROCESSED_WITH_ERRORS;
    }

    /**
     * Get error count from export process
     *
     * @param ExportProcess $exportProcess
     * @return int
     */
    public function getErrorCount(ExportProcess $exportProcess): int
    {
        return count($exportProcess->error_log ?? []);
    }

    /**
     * Get all errors from export process
     *
     * @param ExportProcess $exportProcess
     * @return array
     */
    public function getErrors(ExportProcess $exportProcess): array
    {
        return $exportProcess->error_log ?? [];
    }
}