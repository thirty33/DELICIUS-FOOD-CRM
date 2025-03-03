<?php

namespace App\Classes\ErrorManagment;

use App\Models\ExportProcess;
use App\Models\ImportProcess;
use Throwable;
use Illuminate\Support\Facades\Log;

class ExportErrorHandler
{
    /**
     * Handle export/import errors and update process status
     *
     * @param Throwable $e The exception that was thrown
     * @param int $processId The ID of the export or import process
     * @param string $context The context in which the error occurred
     * @param string $processType The type of process (ExportProcess or ImportProcess)
     * @return void
     */
    public static function handle(
        Throwable $e,
        int $processId,
        string $context = 'general',
        string $processType = 'ExportProcess'
    ): void {
        $error = [
            'row' => 0,
            'attribute' => $context,
            'errors' => [$e->getMessage()],
            'values' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ];

        // Determine which model to use based on the process type
        $process = null;
        $statusWithErrors = null;

        if ($processType === 'ImportProcess') {
            $process = ImportProcess::find($processId);
            $statusWithErrors = ImportProcess::STATUS_PROCESSED_WITH_ERRORS;
            $logPrefix = 'Error en importación';
        } else {
            $process = ExportProcess::find($processId);
            $statusWithErrors = ExportProcess::STATUS_PROCESSED_WITH_ERRORS;
            $logPrefix = 'Error en exportación';
        }

        if ($process) {
            $existingErrors = $process->error_log ?? [];
            $existingErrors[] = $error;

            $process->update([
                'error_log' => $existingErrors,
                'status' => $statusWithErrors
            ]);
        }

        Log::error($logPrefix, [
            'process_id' => $processId,
            'process_type' => $processType,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
