<?php

namespace App\Classes\ErrorManagment;

use App\Models\ExportProcess;
use Throwable;
use Illuminate\Support\Facades\Log;

class ExportErrorHandler
{
    /**
     * Handle export errors and update process status
     */
    public static function handle(Throwable $e, int $exportProcessId, string $context = 'general'): void
    {
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

        $exportProcess = ExportProcess::find($exportProcessId);
        
        if ($exportProcess) {
            $existingErrors = $exportProcess->error_log ?? [];
            $existingErrors[] = $error;

            $exportProcess->update([
                'error_log' => $existingErrors,
                'status' => ExportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);
        }

        Log::error('Error en exportación', [
            'export_process_id' => $exportProcessId,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}