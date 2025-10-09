<?php

namespace App\Services\Billing;

use App\Models\BillingProcess;
use App\Models\BillingProcessAttempt;
use App\Models\Order;
use App\Services\Billing\Contracts\BillingStrategyInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
    private BillingStrategyInterface $strategy;

    public function __construct(BillingStrategyInterface $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Process billing for a BillingProcess.
     *
     * @param BillingProcess $billingProcess
     * @return BillingProcessAttempt
     */
    public function processBilling(BillingProcess $billingProcess): BillingProcessAttempt
    {
        Log::info('Starting billing process', [
            'billing_process_id' => $billingProcess->id,
            'order_id' => $billingProcess->order_id,
            'integration' => $this->strategy->getIntegrationName(),
        ]);

        DB::beginTransaction();

        try {
            // Execute the billing strategy
            $result = $this->strategy->bill($billingProcess->order);

            // Create the attempt record
            $attempt = BillingProcessAttempt::create([
                'billing_process_id' => $billingProcess->id,
                'request_body' => $result['request_body'],
                'response_body' => $result['response_body'],
                'response_status' => $result['response_status'],
            ]);

            // Update billing process status based on response
            $newStatus = $this->determineStatus($result['response_status']);
            $billingProcess->update(['status' => $newStatus]);

            DB::commit();

            Log::info('Billing process completed', [
                'billing_process_id' => $billingProcess->id,
                'attempt_id' => $attempt->id,
                'status' => $newStatus,
            ]);

            return $attempt;
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Billing process failed', [
                'billing_process_id' => $billingProcess->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Create failed attempt
            $attempt = BillingProcessAttempt::create([
                'billing_process_id' => $billingProcess->id,
                'request_body' => json_encode(['error' => 'Failed to execute billing']),
                'response_body' => json_encode(['error' => $e->getMessage()]),
                'response_status' => 500,
            ]);

            $billingProcess->update(['status' => BillingProcess::STATUS_FAILED]);

            throw $e;
        }
    }

    /**
     * Determine the billing process status based on HTTP response status.
     *
     * @param int $responseStatus
     * @return string
     */
    private function determineStatus(int $responseStatus): string
    {
        if ($responseStatus >= 200 && $responseStatus < 300) {
            return BillingProcess::STATUS_SUCCESS;
        }

        return BillingProcess::STATUS_FAILED;
    }

    /**
     * Set a different billing strategy.
     *
     * @param BillingStrategyInterface $strategy
     * @return void
     */
    public function setStrategy(BillingStrategyInterface $strategy): void
    {
        $this->strategy = $strategy;
    }
}
