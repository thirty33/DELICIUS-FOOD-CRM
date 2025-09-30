<?php

namespace App\Jobs;

use App\Models\ImportProcess;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * El código del producto.
     *
     * @var string
     */
    private $productCode;

    /**
     * El ID de la lista de precios.
     *
     * @var int
     */
    private $priceListId;

    /**
     * El precio unitario del producto.
     *
     * @var string|float|int
     */
    private $unitPrice;

    /**
     * El ID del proceso de importación.
     *
     * @var int
     */
    private $importProcessId;

    /**
     * El índice de la fila en el archivo original.
     *
     * @var int
     */
    private $rowIndex;

    /**
     * Create a new job instance.
     */
    public function __construct(string $productCode, int $priceListId, $unitPrice, int $importProcessId, int $rowIndex)
    {
        $this->productCode = $productCode;
        $this->priceListId = $priceListId;
        $this->unitPrice = $unitPrice;
        $this->importProcessId = $importProcessId;
        $this->rowIndex = $rowIndex;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Buscar el producto por código
            $product = Product::select('id', 'code')
                ->where('code', $this->productCode)
                ->first();

            if (!$product) {
                $this->registerError("No se encontró el producto con código: {$this->productCode}");
                return;
            }

            // Transformar el precio
            $transformedPrice = $this->transformPrice($this->unitPrice);

            // Crear o actualizar la línea de lista de precios
            PriceListLine::updateOrCreate(
                [
                    'price_list_id' => $this->priceListId,
                    'product_id' => $product->id,
                ],
                [
                    'unit_price' => $transformedPrice,
                ]
            );

        } catch (\Exception $e) {
            $this->registerError("Error procesando línea de precio: " . $e->getMessage());
        }
    }

    /**
     * Transforma un precio con formato de visualización a entero
     * Ejemplo: "$1,568.33" -> 156833 o 2950.0 -> 295000
     */
    private function transformPrice($price): int
    {
        if (empty($price)) {
            return 0;
        }

        // Si ya es numérico (float o int)
        if (is_numeric($price)) {
            // Multiplicar por 100 para convertir a centavos
            return (int)($price * 100);
        }

        // Si es string, procesar formato de moneda
        $price = trim(str_replace('$', '', $price));

        // Remover las comas de los miles si existen
        $price = str_replace(',', '', $price);

        // Si hay punto decimal, multiplicar por 100 para convertir a centavos
        if (strpos($price, '.') !== false) {
            return (int)(floatval($price) * 100);
        }

        return (int)$price;
    }

    /**
     * Registra un error en el proceso de importación.
     */
    private function registerError(string $errorMessage): void
    {
        try {
            // Obtener información básica de la lista de precios para mensajes
            $priceListInfo = PriceList::select('id', 'name')->find($this->priceListId);

            $error = [
                'row' => $this->rowIndex + 2,
                'attribute' => 'price_list_line',
                'errors' => [$errorMessage],
                'values' => [
                    'product_code' => $this->productCode,
                    'price_list_id' => $this->priceListId,
                    'price_list_name' => $priceListInfo->name ?? 'Unknown',
                    'unit_price' => $this->unitPrice
                ]
            ];

            // Actualizar el proceso de importación con el error
            $importProcess = ImportProcess::find($this->importProcessId);
            if ($importProcess) {
                $existingErrors = $importProcess->error_log ?? [];
                $existingErrors[] = $error;
                
                $importProcess->update([
                    'error_log' => $existingErrors,
                    'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
                ]);
            }

            Log::warning('Error procesando línea de precio', [
                'product_code' => $this->productCode,
                'error' => $errorMessage
            ]);
        } catch (\Exception $e) {
            Log::error('Error al registrar error de procesamiento de línea de precio', [
                'message' => $e->getMessage(),
                'product_code' => $this->productCode
            ]);
        }
    }
}