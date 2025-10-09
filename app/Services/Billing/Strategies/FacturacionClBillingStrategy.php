<?php

namespace App\Services\Billing\Strategies;

use App\Models\Integration;
use App\Models\Order;
use App\Services\Billing\Contracts\BillingStrategyInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacturacionClBillingStrategy implements BillingStrategyInterface
{
    private Integration $integration;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    public function authenticate(): ?string
    {
        Log::info('Facturacion.cl Authentication', [
            'integration_id' => $this->integration->id,
        ]);

        // TODO: Implement actual authentication logic for Facturacion.cl API
        // For now, return a stub token
        return 'facturacion_cl_token_stub';
    }

    public function bill(Order $order): array
    {
        Log::info('Processing Facturacion.cl billing', [
            'order_id' => $order->id,
            'integration_id' => $this->integration->id,
        ]);

        // Build request body for Facturacion.cl API
        $requestBody = $this->buildRequestBody($order);

        try {
            // Determine the URL based on production mode
            $url = $this->integration->production
                ? $this->integration->url
                : $this->integration->url_test;

            // Make HTTP request to Facturacion.cl API
            $response = Http::timeout(30)
                ->withHeaders($this->getHeaders())
                ->post($url, $requestBody);

            Log::info('Facturacion.cl API response received', [
                'order_id' => $order->id,
                'status' => $response->status(),
            ]);

            return [
                'request_body' => json_encode($requestBody),
                'response_body' => $response->body(),
                'response_status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Facturacion.cl billing failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'request_body' => json_encode($requestBody),
                'response_body' => json_encode(['error' => $e->getMessage()]),
                'response_status' => 500,
            ];
        }
    }

    public function getIntegrationName(): string
    {
        return Integration::NAME_FACTURACION_CL;
    }

    private function buildRequestBody(Order $order): array
    {
        // TODO: Build the actual request body according to Facturacion.cl API specification
        return [
            'folio' => $order->order_number,
            'cliente' => [
                'nombre' => $order->user->name,
                'email' => $order->user->email,
            ],
            'monto_total' => $order->grand_total,
            'detalle' => $order->orderLines->map(function ($line) {
                return [
                    'producto' => $line->product_id,
                    'cantidad' => $line->quantity,
                    'precio_unitario' => $line->price,
                ];
            })->toArray(),
        ];
    }

    private function getHeaders(): array
    {
        // TODO: Configure authentication headers for Facturacion.cl API
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
