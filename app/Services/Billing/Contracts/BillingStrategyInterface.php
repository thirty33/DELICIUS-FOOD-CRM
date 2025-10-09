<?php

namespace App\Services\Billing\Contracts;

use App\Models\Order;

interface BillingStrategyInterface
{
    /**
     * Authenticate with the billing API and return the access token.
     *
     * @return string|null Returns the access token or null if authentication fails
     * @throws \Exception
     */
    public function authenticate(): ?string;

    /**
     * Process the billing for an order and return the API response data.
     *
     * @param Order $order
     * @return array Returns an array with 'request_body', 'response_body', and 'response_status'
     */
    public function bill(Order $order): array;

    /**
     * Get the integration name this strategy handles.
     *
     * @return string
     */
    public function getIntegrationName(): string;
}
