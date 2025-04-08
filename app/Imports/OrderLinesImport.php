<?php

namespace App\Imports;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\ImportProcess;
use App\Enums\OrderStatus;
use App\Classes\ErrorManagment\ExportErrorHandler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeImport;
use Maatwebsite\Excel\Events\AfterImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;
use Carbon\Carbon;

class OrderLinesImport implements
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
    use Importable;

    private $importProcessId;
    private $errors = [];
    private $processedOrders = [];

    /**
     * Mapa de cabeceras entre archivo Excel y sistema interno.
     * Las CLAVES son las cabeceras en el archivo Excel y los VALORES son los campos internos.
     */
    private $headingMap = [
        'codigo_de_pedido' => 'order_number',
        'estado' => 'status',
        'fecha_de_orden' => 'created_at',
        'fecha_de_despacho' => 'dispatch_date',
        'usuario' => 'user_email',
        'cliente' => 'client_name',
        'empresa' => 'company_name',
        'nombre_fantasia' => 'fantasy_name',
        'codigo_de_producto' => 'product_code',
        'nombre_producto' => 'product_name',
        'categoria_producto' => 'category_name',
        'cantidad' => 'quantity',
        'precio_neto' => 'unit_price',
        'precio_con_impuesto' => 'unit_price_with_tax',
        'precio_total_neto' => 'total_price_net',
        'precio_total_con_impuesto' => 'total_price_with_tax',
        'parcialmente_programado' => 'partially_scheduled'
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * @return array
     */
    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                ImportProcess::where('id', $this->importProcessId)
                    ->update(['status' => ImportProcess::STATUS_PROCESSING]);

                Log::info('Iniciando importación de líneas de pedido', ['process_id' => $this->importProcessId]);
            },

            AfterImport::class => function (AfterImport $event) {
                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }

                Log::info('Finalizada importación de líneas de pedido', ['process_id' => $this->importProcessId]);
            },
        ];
    }

    /**
     * Convert Excel boolean text to PHP boolean
     */
    private function convertToBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['true', 'verdadero', 'si', 'yes', '1']);
        }

        if (is_numeric($value)) {
            return $value == 1;
        }

        return false;
    }

    /**
     * Limpia comillas simples al inicio de un valor si existe
     */
    private function cleanQuotedValue($value)
    {
        if (is_string($value) && substr($value, 0, 1) === "'") {
            return substr($value, 1);
        }
        return $value;
    }

    /**
     * Get validation rules for import
     * 
     * @return array
     */
    private function getValidationRules(): array
    {
        return [
            '*.codigo_de_pedido' => ['nullable', 'string', 'max:15'], // Aumentado a 15 para incluir comilla
            '*.estado' => ['required', 'string'],
            '*.fecha_de_orden' => ['required', 'string'],
            '*.fecha_de_despacho' => ['required', 'string'],
            '*.usuario' => ['required', 'string', 'email'],
            '*.codigo_de_producto' => ['required', 'string'],
            '*.cantidad' => ['required', 'integer', 'min:1'],
            '*.precio_neto' => ['nullable', 'string'],
            '*.parcialmente_programado' => ['nullable', 'in:0,1,true,false,si,no,yes,no']
        ];
    }

    /**
     * Get custom validation messages
     * 
     * @return array
     */
    private function getValidationMessages(): array
    {
        return [
            '*.codigo_de_pedido.string' => 'El código de pedido debe ser un texto.',
            '*.codigo_de_pedido.max' => 'El código de pedido no debe exceder 15 caracteres.',
            '*.estado.required' => 'El estado del pedido es obligatorio.',
            '*.fecha_de_orden.required' => 'La fecha de orden es obligatoria.',
            '*.fecha_de_despacho.required' => 'La fecha de despacho es obligatoria.',
            '*.usuario.required' => 'El usuario es obligatorio.',
            '*.usuario.email' => 'El usuario debe ser un correo electrónico válido.',
            '*.codigo_de_producto.required' => 'El código de producto es obligatorio.',
            '*.cantidad.required' => 'La cantidad es obligatoria.',
            '*.cantidad.integer' => 'La cantidad debe ser un número entero.',
            '*.cantidad.min' => 'La cantidad debe ser al menos 1.',
            '*.parcialmente_programado.in' => 'El campo parcialmente programado debe tener un valor válido (0, 1, true, false, si, no).',
        ];
    }

    /**
     * @param \Illuminate\Validation\Validator $validator
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $data = $validator->getData();

            Log::debug('OrderLinesImport withValidator validation data', [
                'validation_data' => count($data) > 0 ? array_slice($data, 0, 1) : [],
                'count' => count($data)
            ]);

            foreach ($data as $index => $row) {
                // No validamos existencia de order_number, si no existe se creará uno nuevo

                // Validar el formato de fechas
                if (!empty($row['fecha_de_orden'])) {
                    try {
                        $dateValue = $this->cleanQuotedValue($row['fecha_de_orden']);
                        Carbon::createFromFormat('d/m/Y', $dateValue);
                    } catch (\Exception $e) {
                        $validator->errors()->add(
                            "{$index}.fecha_de_orden",
                            'El formato de la fecha de orden debe ser DD/MM/YYYY.'
                        );
                    }
                }

                if (!empty($row['fecha_de_despacho'])) {
                    try {
                        $dateValue = $this->cleanQuotedValue($row['fecha_de_despacho']);
                        Carbon::createFromFormat('d/m/Y', $dateValue);
                    } catch (\Exception $e) {
                        $validator->errors()->add(
                            "{$index}.fecha_de_despacho",
                            'El formato de la fecha de despacho debe ser DD/MM/YYYY.'
                        );
                    }
                }

                // Validar que el usuario exista
                if (!empty($row['usuario'])) {
                    $userExists = User::where('email', $row['usuario'])->exists();
                    if (!$userExists) {
                        $validator->errors()->add(
                            "{$index}.usuario",
                            'El usuario con el correo electrónico especificado no existe.'
                        );
                    }
                }

                // Validar que el código de producto exista (limpiando comilla inicial)
                if (!empty($row['codigo_de_producto'])) {
                    $productCode = $this->cleanQuotedValue($row['codigo_de_producto']);
                    $productExists = Product::where('code', $productCode)->exists();
                    if (!$productExists) {
                        $validator->errors()->add(
                            "{$index}.codigo_de_producto",
                            "El producto con código {$productCode} no existe en el sistema."
                        );
                    }
                }
            }
        });
    }

    /**
     * Validate and import the collection of rows
     * 
     * @param Collection $rows
     */
    public function collection(Collection $rows)
    {
        try {
            Log::info('OrderLinesImport procesando colección', [
                'count' => $rows->count()
            ]);

            if ($rows->count() > 0) {
                Log::debug('Muestra de datos:', [
                    'primera_fila' => $rows->first()->toArray(),
                    'columnas' => array_keys($rows->first()->toArray())
                ]);
            }

            // Agrupar filas por código de pedido para procesarlas juntas
            $rowsByOrderNumber = $rows->groupBy('codigo_de_pedido');

            DB::beginTransaction();

            try {
                // Procesar cada grupo de pedidos
                foreach ($rowsByOrderNumber as $orderNumber => $orderRows) {
                    // Limpiar comilla inicial del código de pedido
                    $cleanOrderNumber = $this->cleanQuotedValue($orderNumber);
                    $this->processOrderRows($cleanOrderNumber, $orderRows);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            ExportErrorHandler::handle(
                $e,
                $this->importProcessId,
                'general_validation',
                'ImportProcess'
            );

            Log::error('Error general en la importación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Process all rows for a single order
     * 
     * @param string|int $orderNumber
     * @param Collection $rows
     */
    private function processOrderRows($orderNumber, Collection $rows)
    {
        try {
            // Si no hay código de pedido, crear uno nuevo
            if (empty($orderNumber)) {
                $this->createNewOrder($rows);
                return;
            }

            // Usar el primer registro para actualizar la orden
            $firstRow = $rows->first();

            // Si este pedido ya fue procesado, verificar si hay cambios en los campos de la orden
            if (isset($this->processedOrders[$orderNumber])) {
                $processedData = $this->processedOrders[$orderNumber];

                // Verificar si hay cambios en los campos de la orden
                $orderDataChanged =
                    $processedData['status'] !== $firstRow['estado'] ||
                    $processedData['created_at'] !== $firstRow['fecha_de_orden'] ||
                    $processedData['dispatch_date'] !== $firstRow['fecha_de_despacho'] ||
                    $processedData['user_email'] !== $firstRow['usuario'];

                // Si no hay cambios, solo procesar las líneas de pedido
                if (!$orderDataChanged) {
                    $this->processOrderLines($processedData['order_id'], $rows);
                    return;
                }
            }

            // Verificar si la orden existe por el order_number
            $order = Order::where('order_number', $orderNumber)->first();
            if (!$order) {
                // Si no existe la orden con ese order_number, crear una nueva
                $this->createNewOrderWithNumber($orderNumber, $rows);
                return;
            }

            // Convertir el estado a su valor numérico correspondiente
            $status = $this->convertStatusToValue($firstRow['estado']);

            // Obtener ID del usuario por email
            $user = User::where('email', $firstRow['usuario'])->first();
            if (!$user) {
                throw new \Exception("El usuario con correo {$firstRow['usuario']} no existe.");
            }

            // Formatear fechas
            $createdAt = $this->formatDate($firstRow['fecha_de_orden']);
            $dispatchDate = $this->formatDate($firstRow['fecha_de_despacho']);

            // Actualizar la orden
            $order->update([
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate
            ]);

            // Si la fecha de creación es diferente, actualizar manualmente (no se puede por mass assignment)
            if ($order->created_at->format('Y-m-d') !== Carbon::parse($createdAt)->format('Y-m-d')) {
                $order->created_at = $createdAt;
                $order->save();
            }

            // Guardar los datos procesados para comparaciones futuras
            $this->processedOrders[$orderNumber] = [
                'order_id' => $order->id,
                'status' => $firstRow['estado'],
                'created_at' => $firstRow['fecha_de_orden'],
                'dispatch_date' => $firstRow['fecha_de_despacho'],
                'user_email' => $firstRow['usuario']
            ];

            // Procesar las líneas de pedido
            $this->processOrderLines($order->id, $rows);

            Log::info("Pedido actualizado con éxito", [
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
        } catch (\Exception $e) {
            ExportErrorHandler::handle(
                $e,
                $this->importProcessId,
                'order_' . $orderNumber,
                'ImportProcess'
            );

            Log::error("Error procesando pedido {$orderNumber}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create a new order with a specific order number and its order lines
     * 
     * @param string $orderNumber
     * @param Collection $rows
     */
    private function createNewOrderWithNumber($orderNumber, Collection $rows)
    {
        try {
            $firstRow = $rows->first();

            // Convertir el estado a su valor numérico correspondiente
            $status = $this->convertStatusToValue($firstRow['estado']);

            // Obtener ID del usuario por email
            $user = User::where('email', $firstRow['usuario'])->first();
            if (!$user) {
                throw new \Exception("El usuario con correo {$firstRow['usuario']} no existe.");
            }

            // Formatear fechas
            $createdAt = $this->formatDate($firstRow['fecha_de_orden']);
            $dispatchDate = $this->formatDate($firstRow['fecha_de_despacho']);

            // Crear la orden con el order_number específico
            $order = Order::create([
                'order_number' => $orderNumber,
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate,
                'created_at' => $createdAt
            ]);

            // Guardar los datos procesados para comparaciones futuras
            $this->processedOrders[$orderNumber] = [
                'order_id' => $order->id,
                'status' => $firstRow['estado'],
                'created_at' => $firstRow['fecha_de_orden'],
                'dispatch_date' => $firstRow['fecha_de_despacho'],
                'user_email' => $firstRow['usuario']
            ];

            // Procesar las líneas de pedido
            $this->processOrderLines($order->id, $rows);

            Log::info("Pedido creado con order_number específico", [
                'order_id' => $order->id,
                'order_number' => $orderNumber
            ]);
        } catch (\Exception $e) {
            ExportErrorHandler::handle(
                $e,
                $this->importProcessId,
                'new_order_with_number_' . $orderNumber,
                'ImportProcess'
            );

            Log::error("Error creando nuevo pedido con order_number específico", [
                'order_number' => $orderNumber,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create a new order with its order lines
     * 
     * @param Collection $rows
     */
    private function createNewOrder(Collection $rows)
    {
        try {
            $firstRow = $rows->first();

            // Convertir el estado a su valor numérico correspondiente
            $status = $this->convertStatusToValue($firstRow['estado']);

            // Obtener ID del usuario por email
            $user = User::where('email', $firstRow['usuario'])->first();
            if (!$user) {
                throw new \Exception("El usuario con correo {$firstRow['usuario']} no existe.");
            }

            // Formatear fechas
            $createdAt = $this->formatDate($firstRow['fecha_de_orden']);
            $dispatchDate = $this->formatDate($firstRow['fecha_de_despacho']);

            // Crear la orden (el order_number se generará automáticamente en el modelo)
            $order = Order::create([
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate,
                'created_at' => $createdAt
            ]);

            // Guardar los datos procesados para comparaciones futuras usando el order_number como clave
            $this->processedOrders[$order->order_number] = [
                'order_id' => $order->id,
                'status' => $firstRow['estado'],
                'created_at' => $firstRow['fecha_de_orden'],
                'dispatch_date' => $firstRow['fecha_de_despacho'],
                'user_email' => $firstRow['usuario']
            ];

            // Procesar las líneas de pedido
            $this->processOrderLines($order->id, $rows);

            Log::info("Pedido creado con éxito", [
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
        } catch (\Exception $e) {
            ExportErrorHandler::handle(
                $e,
                $this->importProcessId,
                'new_order',
                'ImportProcess'
            );

            Log::error("Error creando nuevo pedido", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process order lines for an order
     * 
     * @param int $orderId
     * @param Collection $rows
     */
    private function processOrderLines($orderId, Collection $rows)
    {
        try {
            // Eliminar las líneas de pedido existentes
            OrderLine::where('order_id', $orderId)->delete();

            // Crear nuevas líneas de pedido
            foreach ($rows as $row) {
                // Limpiar comilla inicial del código de producto
                $productCode = $this->cleanQuotedValue($row['codigo_de_producto']);

                // Obtener el producto por código
                $product = Product::where('code', $productCode)->first();
                if (!$product) {
                    throw new \Exception("El producto con código {$productCode} no existe.");
                }

                $unitPrice = OrderLine::calculateUnitPrice($product->id, $orderId);

                // Convertir parcialmente programado a booleano
                $partiallyScheduled = $this->convertToBoolean($row['parcialmente_programado'] ?? false);

                // Crear la línea de pedido
                OrderLine::create([
                    'order_id' => $orderId,
                    'product_id' => $product->id,
                    'quantity' => $row['cantidad'],
                    'partially_scheduled' => $partiallyScheduled,
                    'unit_price' => $unitPrice,
                ]);
            }

            Log::info("Líneas de pedido procesadas con éxito", [
                'order_id' => $orderId,
                'lines_count' => $rows->count()
            ]);
        } catch (\Exception $e) {
            ExportErrorHandler::handle(
                $e,
                $this->importProcessId,
                'order_lines_' . $orderId,
                'ImportProcess'
            );

            Log::error("Error procesando líneas de pedido para orden {$orderId}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Convert status string to its corresponding value
     * 
     * @param string $statusLabel
     * @return int|string
     */
    private function convertStatusToValue($statusLabel)
    {
        // Convertir el label del estado a su valor correspondiente
        foreach (OrderStatus::cases() as $status) {
            if ($status->getLabel() === $statusLabel) {
                return $status->value;
            }
        }

        // Si no se encuentra, devolver el label tal cual
        return $statusLabel;
    }

    /**
     * Format date from Excel format (DD/MM/YYYY) to database format (YYYY-MM-DD)
     * 
     * @param string $dateValue
     * @return string
     */
    private function formatDate($dateValue)
    {
        // Eliminar comilla simple al inicio si existe
        $dateValue = $this->cleanQuotedValue($dateValue);

        return Carbon::createFromFormat('d/m/Y', $dateValue)->format('Y-m-d');
    }

    /**
     * Validation rules
     * 
     * @return array
     */
    public function rules(): array
    {
        return $this->getValidationRules();
    }

    /**
     * Custom validation messages
     * 
     * @return array
     */
    public function customValidationMessages(): array
    {
        return $this->getValidationMessages();
    }

    /**
     * Handle validation failures
     * 
     * @param Failure ...$failures
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
            $importProcess = ImportProcess::find($this->importProcessId);
            $existingErrors = $importProcess->error_log ?? [];

            // Agregar el nuevo error al array existente
            $existingErrors[] = $error;

            // Actualizar el error_log en el ImportProcess
            $importProcess->update([
                'error_log' => $existingErrors,
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);

            Log::warning('Fallo en validación de importación de líneas de pedido', [
                'import_process_id' => $this->importProcessId,
                'row_number' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ]);
        }
    }

    /**
     * Handle import errors
     * 
     * @param Throwable $e
     */
    public function onError(Throwable $e)
    {
        $error = [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        // Obtener el proceso actual y sus errores existentes
        $importProcess = ImportProcess::find($this->importProcessId);
        $existingErrors = $importProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ImportProcess
        $importProcess->update([
            'error_log' => $existingErrors,
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error en importación de líneas de pedido', [
            'import_process_id' => $this->importProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
