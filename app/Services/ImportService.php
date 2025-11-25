<?php

namespace App\Services;

use App\Models\ImportProcess;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

/**
 * Generic Import Service
 *
 * Handles Excel import operations with standardized error handling and process tracking.
 * Decouples import logic from specific importer classes and test contexts.
 */
class ImportService
{
    /**
     * Execute an import operation
     *
     * @param string $importerClass Fully qualified importer class name
     * @param string $filePath Path to the Excel file to import
     * @param string $importType Type of import (use ImportProcess::TYPE_* constants)
     * @param array $importerArguments Arguments to pass to the importer constructor
     * @param string|null $disk Storage disk (default: null for local, 's3' for S3)
     * @return ImportProcess The import process record with updated status and errors
     */
    public function import(
        string $importerClass,
        string $filePath,
        string $importType,
        array $importerArguments = [],
        ?string $disk = null
    ): ImportProcess {
        // Create import process record
        $importProcess = ImportProcess::create([
            'type' => $importType,
            'status' => ImportProcess::STATUS_QUEUED,
            'file_url' => $filePath,
        ]);

        try {
            // Add import process ID as last argument for the importer
            $arguments = array_merge($importerArguments, [$importProcess->id]);

            // Create importer instance with provided arguments
            $importer = new $importerClass(...$arguments);

            // Execute import
            if ($disk) {
                Excel::import($importer, $filePath, $disk);
            } else {
                Excel::import($importer, $filePath);
            }

            Log::info('Import completed successfully', [
                'import_process_id' => $importProcess->id,
                'type' => $importType,
                'file' => $filePath,
            ]);
        } catch (\Exception $e) {
            Log::error('Import failed with exception', [
                'import_process_id' => $importProcess->id,
                'type' => $importType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update process status if not already set by importer
            $importProcess->refresh();
            if ($importProcess->status === ImportProcess::STATUS_PROCESSING) {
                $importProcess->update([
                    'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
                    'error_log' => array_merge($importProcess->error_log ?? [], [[
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]]),
                ]);
            }
        }

        return $importProcess->refresh();
    }

    /**
     * Execute import with repository injection (for imports that need repositories)
     *
     * @param string $importerClass Fully qualified importer class name
     * @param string $filePath Path to the Excel file to import
     * @param string $importType Type of import (use ImportProcess::TYPE_* constants)
     * @param object $repository Repository instance to inject
     * @param string|null $disk Storage disk (default: null for local, 's3' for S3)
     * @return ImportProcess The import process record with updated status and errors
     */
    public function importWithRepository(
        string $importerClass,
        string $filePath,
        string $importType,
        object $repository,
        ?string $disk = null
    ): ImportProcess {
        return $this->import(
            $importerClass,
            $filePath,
            $importType,
            [$repository],
            $disk
        );
    }

    /**
     * Check if import completed successfully
     *
     * @param ImportProcess $importProcess
     * @return bool
     */
    public function wasSuccessful(ImportProcess $importProcess): bool
    {
        return $importProcess->status === ImportProcess::STATUS_PROCESSED;
    }

    /**
     * Check if import completed with errors
     *
     * @param ImportProcess $importProcess
     * @return bool
     */
    public function hasErrors(ImportProcess $importProcess): bool
    {
        return $importProcess->status === ImportProcess::STATUS_PROCESSED_WITH_ERRORS;
    }

    /**
     * Get error count from import process
     *
     * @param ImportProcess $importProcess
     * @return int
     */
    public function getErrorCount(ImportProcess $importProcess): int
    {
        return count($importProcess->error_log ?? []);
    }

    /**
     * Get all errors from import process
     *
     * @param ImportProcess $importProcess
     * @return array
     */
    public function getErrors(ImportProcess $importProcess): array
    {
        return $importProcess->error_log ?? [];
    }
}