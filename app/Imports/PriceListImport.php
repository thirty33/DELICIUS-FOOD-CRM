<?php

namespace App\Imports;

use App\Models\ImportProcess;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;
use App\Jobs\ProcessProduct;

class PriceListImport implements
    ToCollection,
    WithHeadingRow,
    SkipsEmptyRows,
    WithEvents,
    ShouldQueue,
    WithChunkReading,
    WithValidation,
    SkipsOnError,
    SkipsOnFailure
{
    private $importProcessId;

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    /**
     * Process the collection of rows.
     */
    public function collection(Collection $rows)
    {
        Validator::make($rows->toArray(), $this->getValidationRules(), $this->getValidationMessages())->validate();

        foreach ($rows as $index => $row) {
            $priceListData = $this->preparePriceListData($row);

            $priceList = PriceList::updateOrCreate(
                ['name' => $priceListData['name']],
                $priceListData
            );

            // nombre_producto column is read-only, not processed on import

            if (!empty($row['codigo_de_producto']) && isset($row['precio_unitario'])) {
                $this->processPriceListLine($row, $priceList->id, $index);
            }
        }
    }

    /**
     * Prepare price list data from row.
     */
    private function preparePriceListData(Collection $row): array
    {
        return [
            'name' => $row['nombre_de_lista_de_precio'],
            'description' => $row['descripcion'] ?? null,
            'min_price_order' => $this->transformPrice($row['precio_minimo'] ?? 0),
        ];
    }

    /**
     * Process price list line by dispatching a separate job.
     */
    private function processPriceListLine(Collection $row, int $priceListId, int $rowIndex): void
    {
        ProcessProduct::dispatch(
            $row['codigo_de_producto'],
            $priceListId,
            $row['precio_unitario'],
            $this->importProcessId,
            $rowIndex
        );
    }

    /**
     * Transform price from display format to integer.
     */
    private function transformPrice($price): int
    {
        if (empty($price)) {
            return 0;
        }

        // Remove currency symbol and spaces
        $price = trim(str_replace('$', '', $price));

        // Remove thousand separators
        $price = str_replace(',', '', $price);

        // If decimal point exists, multiply by 100 to convert to cents
        if (strpos($price, '.') !== false) {
            return (int)(floatval($price) * 100);
        }

        return (int)$price;
    }

    /**
     * Define validation rules.
     */
    public function rules(): array
    {
        return [
            '*.nombre_de_lista_de_precio' => ['required', 'string', 'min:2', 'max:200'],
            '*.descripcion' => ['nullable', 'string'],
            '*.precio_minimo' => ['nullable'],
            '*.nombre_producto' => ['nullable', 'string'],
            '*.codigo_de_producto' => ['nullable', 'string'],
            '*.precio_unitario' => ['required'],
        ];
    }

    /**
     * Get validation rules.
     */
    private function getValidationRules(): array
    {
        return $this->rules();
    }

    /**
     * Get validation messages.
     */
    private function getValidationMessages(): array
    {
        return [
            '*.nombre_de_lista_de_precio.required' => 'Price list name is required',
            '*.nombre_de_lista_de_precio.min' => 'Name must be at least 2 characters',
            '*.nombre_de_lista_de_precio.max' => 'Name must not exceed 200 characters',
            '*.precio_minimo.regex' => 'Minimum price must have a valid format (example: $1,568.33 or 1568.33)',
            '*.codigo_de_producto.exists' => 'Product code does not exist',
            '*.precio_unitario.required_with' => 'Unit price is required when a product code is specified',
            '*.precio_unitario.regex' => 'Unit price must have a valid format (example: $1,568.33 or 1568.33)',
        ];
    }

    /**
     * Define custom validation messages.
     */
    public function customValidationMessages(): array
    {
        return $this->getValidationMessages();
    }

    /**
     * Register import events.
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function () {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);
            },
            AfterImport::class => function () {
                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }

                Log::info('Price list import completed', ['process_id' => $this->importProcessId]);
            },
        ];
    }

    /**
     * Define chunk size for processing.
     */
    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * Handle validation failures.
     */
    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $error = [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ];

            $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
            $existingErrors = $importProcess->error_log ?? [];

            $existingErrors[] = $error;

            $importProcess->update([
                'error_log' => $existingErrors,
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);

            Log::warning('Price list import validation failure', [
                'import_process_id' => $this->importProcessId,
                'row_number' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ]);
        }
    }

    /**
     * Handle general errors.
     */
    public function onError(Throwable $e)
    {
        $error = [
            'row' => 0,
            'attribute' => 'import_error',
            'errors' => [$e->getMessage()],
            'values' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ];

        $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        $existingErrors[] = $error;

        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('General error in price list import', [
            'import_process_id' => $this->importProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    /**
     * Add additional validation logic.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();
            foreach ($data as $index => $row) {
                // Custom validation for precio_minimo
                if (isset($row['precio_minimo'])) {
                    $precioMinimo = $row['precio_minimo'];
                    if (empty($precioMinimo) || $precioMinimo === '-') {
                        // transformPrice already handles empty values by returning 0
                    } elseif (!is_numeric($precioMinimo) && !empty($precioMinimo)) {
                        // If not empty and not numeric, must be valid price format
                        $cleanPrice = trim(str_replace(['$', ','], '', $precioMinimo));
                        if (!is_numeric($cleanPrice)) {
                            $validator->errors()->add(
                                "{$index}.precio_minimo",
                                'Minimum price must be a valid number or empty.'
                            );
                        }
                    }
                }

                // Additional validation for products
                if (isset($row['codigo_de_producto']) && !empty($row['codigo_de_producto'])) {
                    if (!isset($row['precio_unitario']) || empty($row['precio_unitario'])) {
                        $validator->errors()->add(
                            "{$index}.precio_unitario",
                            'Unit price is required when a product code is specified.'
                        );
                    }

                    // Verify product existence
                    $exists = Product::where('code', $row['codigo_de_producto'])->exists();
                    if (!$exists) {
                        $validator->errors()->add(
                            "{$index}.codigo_de_producto",
                            "Product with code '{$row['codigo_de_producto']}' does not exist."
                        );
                    }
                }

                // nombre_producto column is read-only, no additional validation required
            }
        });
    }
}
