<?php

namespace App\Services\Billing\Strategies;

use App\Models\Integration;
use App\Models\Order;
use App\Services\Billing\Contracts\BillingStrategyInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DefontanaBillingStrategy implements BillingStrategyInterface
{
    private Integration $integration;
    private ?string $accessToken = null;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    public function authenticate(): ?string
    {
        // Check if we have a valid cached token
        if ($this->hasValidToken()) {
            Log::info('Using cached Defontana token', [
                'integration_id' => $this->integration->id,
                'expires_at' => $this->integration->token_expiration_time,
            ]);

            $this->accessToken = $this->integration->temporary_token;
            return $this->accessToken;
        }

        // Token doesn't exist or is expired, request a new one
        Log::info('Requesting new Defontana token', [
            'integration_id' => $this->integration->id,
            'reason' => $this->integration->temporary_token ? 'token_expired' : 'no_token',
        ]);

        $credentials = [
            'Client' => config('defontana.auth.client'),
            'Company' => config('defontana.auth.company'),
            'User' => config('defontana.auth.user'),
            'Password' => config('defontana.auth.password'),
        ];

        $baseUrl = $this->integration->production
            ? $this->integration->url
            : $this->integration->url_test;

        $authUrl = $baseUrl . config('defontana.endpoints.auth') . '?' . http_build_query($credentials);

        Log::info('Defontana Authentication Request', [
            'url' => $baseUrl . config('defontana.endpoints.auth'),
            'client' => $credentials['Client'],
            'company' => $credentials['Company'],
            'user' => $credentials['User'],
        ]);

        try {
            $response = Http::timeout(config('defontana.timeout'))
                ->get($authUrl);

            $data = $response->json();

            if (!$response->successful() || !($data['success'] ?? false) || empty($data['access_token'])) {
                Log::error('Defontana Authentication Failed', [
                    'status' => $response->status(),
                    'response' => $data,
                ]);
                return null;
            }

            $this->accessToken = $data['access_token'];

            // Cache the token in the integration record
            $expiresIn = $data['expires_in'] ?? 3600; // Default to 1 hour if not provided
            $expirationTime = now()->addSeconds($expiresIn);

            $this->integration->update([
                'temporary_token' => $this->accessToken,
                'token_expiration_time' => $expirationTime,
            ]);

            Log::info('Defontana Authentication Success', [
                'token_length' => strlen($this->accessToken),
                'expires_in' => $expiresIn,
                'expires_at' => $expirationTime,
            ]);

            return $this->accessToken;
        } catch (\Exception $e) {
            Log::error('Defontana Authentication Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if the integration has a valid cached token.
     *
     * @return bool
     */
    private function hasValidToken(): bool
    {
        // Refresh the integration model to get the latest data
        $this->integration->refresh();

        if (empty($this->integration->temporary_token)) {
            return false;
        }

        if (!$this->integration->token_expiration_time) {
            return false;
        }

        // Check if token is still valid (with 5 minute buffer before expiration)
        return now()->addMinutes(5)->lessThan($this->integration->token_expiration_time);
    }

    public function bill(Order $order): array
    {
        Log::info('Processing Defontana billing', [
            'order_id' => $order->id,
            'integration_id' => $this->integration->id,
        ]);

        try {
            // Step 1: Authenticate
            $token = $this->authenticate();

            if (!$token) {
                throw new \RuntimeException('Failed to authenticate with Defontana API');
            }

            // Step 2: Build sale data
            $saleData = $this->buildSaleData($order);

            // Step 3: Send sale to Defontana
            $baseUrl = $this->integration->production
                ? $this->integration->url
                : $this->integration->url_test;

            $saveSaleUrl = $baseUrl . config('defontana.endpoints.save_sale');

            Log::info('Defontana SaveSale Request', [
                'order_id' => $order->id,
                'url' => $saveSaleUrl,
            ]);

            $response = Http::timeout(config('defontana.timeout'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'bearer ' . $token,
                ])
                ->post($saveSaleUrl, ['Sale' => $saleData]);

            Log::info('Defontana SaveSale Response', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [
                'request_body' => json_encode(['Sale' => $saleData]),
                'response_body' => $response->body(),
                'response_status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Defontana billing failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'request_body' => json_encode(['error' => 'Failed to process billing']),
                'response_body' => json_encode(['error' => $e->getMessage()]),
                'response_status' => 500,
            ];
        }
    }

    public function getIntegrationName(): string
    {
        return Integration::NAME_DEFONTANA;
    }

    private function buildSaleData(Order $order): array
    {
        $now = now();

        return [
            'documentType' => config('defontana.sale_defaults.document_type'),
            'firstFolio' => 0,
            'lastFolio' => 0,
            'emissionDate' => [
                'day' => (int) $now->format('d'),
                'month' => (int) $now->format('m'),
                'year' => (int) $now->format('Y'),
            ],
            'firstFeePaid' => [
                'day' => (int) $now->format('d'),
                'month' => (int) $now->format('m'),
                'year' => (int) $now->format('Y'),
            ],
            'clientFile' => $order->user->rut ?? '66666666-6',
            'contactIndex' => 0,
            'paymentCondition' => config('defontana.sale_defaults.payment_condition'),
            'sellerFileId' => config('defontana.sale_defaults.seller_file_id'),
            'clientAnalysis' => [
                'accountNumber' => '',
                'businessCenter' => '',
                'classifier01' => '',
                'classifier02' => '',
            ],
            'saleAnalysis' => [
                'accountNumber' => '',
                'businessCenter' => '',
                'classifier01' => '',
                'classifier02' => '',
            ],
            'billingCoin' => config('defontana.sale_defaults.billing_coin'),
            'billingRate' => config('defontana.sale_defaults.billing_rate'),
            'shopId' => config('defontana.sale_defaults.shop_id'),
            'priceList' => config('defontana.sale_defaults.price_list'),
            'giro' => config('defontana.sale_defaults.giro'),
            'district' => '',
            'contact' => $order->user->email ?? '',
            'storage' => [
                'code' => config('defontana.sale_defaults.storage_code'),
                'motive' => config('defontana.sale_defaults.storage_motive'),
                'storageAnalysis' => [
                    'accountNumber' => '',
                    'businessCenter' => '',
                    'classifier01' => '',
                    'classifier02' => '',
                ],
            ],
            'details' => $order->orderLines->map(function ($line) {
                return [
                    'type' => 'A',
                    'code' => $line->product->code ?? 'PROD' . $line->product_id,
                    'count' => $line->quantity,
                    'productName' => $line->product->name ?? 'Product',
                    'productNameBarCode' => $line->product->code ?? 'PROD' . $line->product_id,
                    'price' => $line->price,
                    'unit' => 'UN',
                    'analysis' => [
                        'accountNumber' => '',
                        'businessCenter' => '',
                        'classifier01' => '',
                        'classifier02' => '',
                    ],
                ];
            })->toArray(),
            'saleTaxes' => [
                [
                    'code' => config('defontana.sale_defaults.tax_code'),
                    'value' => config('defontana.sale_defaults.tax_value'),
                ],
            ],
            'ventaRecDesGlobal' => [],
            'gloss' => 'Pedido ' . $order->order_number,
            'isTransferDocument' => config('defontana.sale_defaults.is_transfer_document'),
        ];
    }
}
