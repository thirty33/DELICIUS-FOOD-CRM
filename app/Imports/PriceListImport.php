<?php

namespace App\Imports;

use App\Models\Company;
use App\Models\ImportProcess;
use App\Models\PriceList;
use App\Models\PriceListLine;
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
use App\Jobs\ProcessCompany;
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
    /**
     * @var int
     */
    private $importProcessId;

    /**
     * @var array
     */
    private $headingMap = [
        'nombre_de_lista_de_precio' => 'name',
        'precio_minimo' => 'min_price_order',
        'descripcion' => 'description',
        'numeros_de_registro_de_empresas' => 'company_registration_numbers',
        'codigo_de_producto' => 'product_code',
        'precio_unitario' => 'unit_price',
    ];

    /**
     * Constructor with optimized dependencies.
     */
    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    /**
     * Process the collection of rows.
     */
    public function collection(Collection $rows)
    {
        // try {
            Validator::make($rows->toArray(), $this->getValidationRules(), $this->getValidationMessages())->validate();

            foreach ($rows as $index => $row) {
                // try {
                    // Preparar datos de la lista de precios
                    $priceListData = $this->preparePriceListData($row);

                    // Crear o actualizar la lista de precios
                    $priceList = PriceList::updateOrCreate(
                        ['name' => $priceListData['name']],
                        $priceListData
                    );

                    // Procesar números de registro de empresas si existen y no son '-'
                    if (!empty($row['numeros_de_registro_de_empresas']) && $row['numeros_de_registro_de_empresas'] !== '-') {
                        $this->processCompanyRegistrationNumbers($row['numeros_de_registro_de_empresas'], $priceList->id, $index);
                    }

                    // Procesar línea de lista de precios (si hay código de producto y precio unitario)
                    if (!empty($row['codigo_de_producto']) && isset($row['precio_unitario'])) {
                        $this->processPriceListLine($row, $priceList->id, $index);
                    }
                // } catch (\Exception $e) {
                //     // $this->handleRowError($e, $index, $row);
                // }
            }
        // } catch (\Exception $e) {
        //     // $this->handleImportError($e);
        // }
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
     * Process company registration numbers by dispatching separate jobs.
     */
    private function processCompanyRegistrationNumbers(string $registrationNumbers, int $priceListId, int $rowIndex): void
    {
        $regNumbers = array_map('trim', explode(',', $registrationNumbers));

        foreach ($regNumbers as $regNumber) {
            // Omitir si está vacío o es '-'
            if (empty($regNumber) || $regNumber === '-') {
                continue;
            }
            
            // Despachar un job separado para cada empresa
            ProcessCompany::dispatch(
                $regNumber,
                $priceListId,
                $this->importProcessId,
                $rowIndex
            );
        }
    }

    /**
     * Process price list line by dispatching a separate job.
     */
    private function processPriceListLine(Collection $row, int $priceListId, int $rowIndex): void
    {
        // Despachar un job para procesar la línea de precio
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

        // Remover el símbolo de moneda y espacios
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
     * Define validation rules.
     */
    public function rules(): array
    {
        return [
            '*.nombre_de_lista_de_precio' => ['required', 'string', 'min:2', 'max:200'],
            '*.descripcion' => ['nullable', 'string'],
            '*.precio_minimo' => ['nullable'],
            '*.numeros_de_registro_de_empresas' => ['nullable', 'string'],
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
            '*.nombre_de_lista_de_precio.required' => 'El nombre de la lista de precios es requerido',
            '*.nombre_de_lista_de_precio.min' => 'El nombre debe tener al menos 2 caracteres',
            '*.nombre_de_lista_de_precio.max' => 'El nombre no debe exceder los 200 caracteres',
            '*.precio_minimo.regex' => 'El precio mínimo debe tener un formato válido (ejemplo: $1,568.33 o 1568.33)',
            '*.codigo_de_producto.exists' => 'El código de producto no existe',
            '*.precio_unitario.required_with' => 'El precio unitario es requerido cuando se especifica un código de producto',
            '*.precio_unitario.regex' => 'El precio unitario debe tener un formato válido (ejemplo: $1,568.33 o 1568.33)',
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
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);
            },
            AfterImport::class => function (AfterImport $event) {
                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }

                Log::info('Finalizada importación de listas de precios', ['process_id' => $this->importProcessId]);
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
     * Handle row processing error.
     */
    private function handleRowError(\Exception $e, int $index, $row)
    {
        // Limitar el tamaño de los datos registrados
        $rowData = array_map(function ($value) {
            // Si es una cadena larga, truncarla
            if (is_string($value) && strlen($value) > 200) {
                return substr($value, 0, 200) . '...';
            }
            return $value;
        }, $row->toArray());

        $error = [
            'row' => $index + 2,
            'attribute' => 'row_processing',
            'errors' => [$e->getMessage()],
            'values' => $rowData
        ];

        // Obtener el proceso actual y sus errores existentes
        $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ImportProcess
        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::warning('Error procesando fila en importación de listas de precio', [
            'import_process_id' => $this->importProcessId,
            'row_number' => $index + 2,
            'error' => $e->getMessage(),
            'values' => $rowData
        ]);
    }

    /**
     * Handle import process error.
     */
    private function handleImportError(\Exception $e)
    {
        $error = [
            'row' => 0,
            'attribute' => 'import_process',
            'errors' => [$e->getMessage()],
            'values' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ];

        // Obtener el proceso actual y sus errores existentes
        $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ImportProcess
        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error en proceso de importación de listas de precio', [
            'import_process_id' => $this->importProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    /**
     * Update import process with error log.
     * Esta función ya no se necesita porque ahora se manejan los errores directamente
     * en las funciones específicas siguiendo el patrón de onFailure
     */
    private function updateImportProcessError(array $error)
    {
        // Esta función ya no se utiliza
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

            // Obtener el proceso actual y sus errores existentes
            $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
            $existingErrors = $importProcess->error_log ?? [];

            // Agregar el nuevo error al array existente
            $existingErrors[] = $error;

            // Actualizar el error_log en el ImportProcess
            $importProcess->update([
                'error_log' => $existingErrors,
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);

            Log::warning('Fallo en validación de importación de listas de precio', [
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

        // Obtener el proceso actual y sus errores existentes
        $importProcess = \App\Models\ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ImportProcess
        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error general en importación de listas de precio', [
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
                // Validación custom para precio_minimo
                if (isset($row['precio_minimo'])) {
                    $precioMinimo = $row['precio_minimo'];
                    if (empty($precioMinimo) || $precioMinimo === '-') {
                        // El transformPrice ya maneja valores vacíos retornando 0
                        // No necesitamos modificar aquí, solo validar formato si no está vacío
                    } elseif (!is_numeric($precioMinimo) && !empty($precioMinimo)) {
                        // Si no está vacío y no es numérico, debe ser formato de precio válido
                        $cleanPrice = trim(str_replace(['$', ','], '', $precioMinimo));
                        if (!is_numeric($cleanPrice)) {
                            $validator->errors()->add(
                                "{$index}.precio_minimo",
                                'El precio mínimo debe ser un número válido o estar vacío.'
                            );
                        }
                    }
                }

                // Validación adicional para productos
                if (isset($row['codigo_de_producto']) && !empty($row['codigo_de_producto'])) {
                    if (!isset($row['precio_unitario']) || empty($row['precio_unitario'])) {
                        $validator->errors()->add(
                            "{$index}.precio_unitario",
                            'El precio unitario es requerido cuando se especifica un código de producto.'
                        );
                    }

                    // Verificar existencia del producto (reemplazando la regla exists:products,code)
                    $exists = Product::where('code', $row['codigo_de_producto'])->exists();
                    if (!$exists) {
                        $validator->errors()->add(
                            "{$index}.codigo_de_producto",
                            "El producto con código '{$row['codigo_de_producto']}' no existe."
                        );
                    }
                }

                // Validación adicional para números de registro de empresa
                if (isset($row['numeros_de_registro_de_empresas'])) {
                    $regNumbers = $row['numeros_de_registro_de_empresas'];
                    
                    // Si está vacío o es '-', no validar (se omitirá en el procesamiento)
                    if (!empty($regNumbers) && $regNumbers !== '-') {
                        $regNumbersList = array_map('trim', explode(',', $regNumbers));
                        foreach ($regNumbersList as $regNumber) {
                            // Saltar si es '-' individual
                            if ($regNumber === '-' || empty($regNumber)) {
                                continue;
                            }
                            
                            // Comprobar existencia sin cargar el modelo completo
                            $exists = Company::where('registration_number', $regNumber)->exists();
                            if (!$exists) {
                                $validator->errors()->add(
                                    "{$index}.numeros_de_registro_de_empresas",
                                    "La empresa con número de registro '{$regNumber}' no existe."
                                );
                            }
                        }
                    }
                }
            }
        });
    }
}
