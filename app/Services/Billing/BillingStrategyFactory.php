<?php

namespace App\Services\Billing;

use App\Models\Integration;
use App\Services\Billing\Contracts\BillingStrategyInterface;
use App\Services\Billing\Strategies\DefontanaBillingStrategy;
use App\Services\Billing\Strategies\FacturacionClBillingStrategy;

class BillingStrategyFactory
{
    /**
     * Create the appropriate billing strategy based on the integration.
     *
     * @param Integration $integration
     * @return BillingStrategyInterface
     * @throws \InvalidArgumentException
     */
    public static function create(Integration $integration): BillingStrategyInterface
    {
        return match ($integration->name) {
            Integration::NAME_DEFONTANA => new DefontanaBillingStrategy($integration),
            Integration::NAME_FACTURACION_CL => new FacturacionClBillingStrategy($integration),
            default => throw new \InvalidArgumentException(
                "No billing strategy found for integration: {$integration->name}"
            ),
        };
    }

    /**
     * Create billing strategy for the active billing integration.
     *
     * @return BillingStrategyInterface
     * @throws \RuntimeException
     */
    public static function createForActiveIntegration(): BillingStrategyInterface
    {
        $integration = Integration::where('type', Integration::TYPE_BILLING)
            ->where('active', true)
            ->first();

        if (!$integration) {
            throw new \RuntimeException('No active billing integration found');
        }

        return self::create($integration);
    }
}
