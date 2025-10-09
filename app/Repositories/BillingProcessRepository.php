<?php

namespace App\Repositories;

use App\Models\BillingProcess;
use App\Services\Billing\BillingService;
use App\Services\Billing\BillingStrategyFactory;
use Illuminate\Support\Facades\Log;

class BillingProcessRepository
{
    /**
     * Process billing for a BillingProcess record.
     *
     * @param BillingProcess $billingProcess
     * @return array Returns ['success' => bool, 'message' => string, 'attempt' => BillingProcessAttempt|null]
     */
    public function processBilling(BillingProcess $billingProcess): array
    {
        try {
            // Create billing strategy using the factory
            $strategy = BillingStrategyFactory::create($billingProcess->integration);

            // Create billing service with the strategy
            $billingService = new BillingService($strategy);

            // Process billing
            $attempt = $billingService->processBilling($billingProcess);

            Log::info('Billing processed successfully', [
                'billing_process_id' => $billingProcess->id,
                'attempt_id' => $attempt->id,
                'status' => $billingProcess->fresh()->status,
            ]);

            return [
                'success' => true,
                'message' => 'El proceso de facturación ha sido ejecutado exitosamente.',
                'attempt' => $attempt,
            ];
        } catch (\Exception $e) {
            Log::error('Error processing billing', [
                'billing_process_id' => $billingProcess->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Ha ocurrido un error: ' . $e->getMessage(),
                'attempt' => null,
            ];
        }
    }
}
