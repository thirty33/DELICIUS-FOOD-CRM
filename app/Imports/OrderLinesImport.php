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
     * Orden actualizado para coincidir con OrderLineExport.
     */
    private $headingMap = [
        'id_orden' => 'order_id',
        'codigo_de_pedido' => 'order_number',
        'estado' => 'status',
        'fecha_de_orden' => 'created_at',
        'fecha_de_despacho' => 'dispatch_date',
        'empresa' => 'company_name',
        'nombre_fantasia' => 'fantasy_name',
        'cliente' => 'client_name',
        'usuario' => 'user_email',
        'categoria_producto' => 'category_name',
        'codigo_de_producto' => 'product_code',
        'nombre_producto' => 'product_name',
        'cantidad' => 'quantity',
        'precio_neto' => 'unit_price',
        'precio_con_impuesto' => 'unit_price_with_tax',
        'precio_total_neto' => 'total_price_net',
        'precio_total_con_impuesto' => 'total_price_with_tax',
        'parcialmente_programado' => 'partially_scheduled',
        'precio_transporte' => 'dispatch_cost'
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
     * Find user by email or nickname
     */
    private function findUserByEmailOrNickname($value): ?User
    {
        if (empty($value)) {
            return null;
        }

        return User::where('email', $value)
            ->orWhere('nickname', $value)
            ->first();
    }

    /**
     * Find order by ID and order number combination
     */
    private function findOrderByIdAndNumber($id, $orderNumber): ?Order
    {
        if (empty($id) || empty($orderNumber)) {
            return null;
        }

        return Order::where('id', $id)
            ->where('order_number', $orderNumber)
            ->first();
    }

    /**
     * Get validation rules for import
     * 
     * @return array
     */
    private function getValidationRules(): array
    {
        return [
            '*.id_orden' => ['nullable', 'integer'],
            '*.codigo_de_pedido' => ['nullable'],
            '*.estado' => ['required', 'string'],
            '*.fecha_de_orden' => ['required', 'string'],
            '*.fecha_de_despacho' => ['required', 'string'],
            '*.usuario' => ['required', 'string'],
            '*.codigo_de_producto' => ['required', 'string'],
            '*.cantidad' => ['required', 'integer', 'min:1'],
            '*.precio_neto' => ['nullable', 'numeric'],
            '*.parcialmente_programado' => ['nullable', 'in:0,1,true,false,si,no,yes,no'],
            '*.precio_transporte' => ['nullable', 'numeric', 'min:0']
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
            '*.id_orden.integer' => 'El ID de orden debe ser un número entero.',
            '*.estado.required' => 'El estado del pedido es obligatorio.',
            '*.fecha_de_orden.required' => 'La fecha de orden es obligatoria.',
            '*.fecha_de_despacho.required' => 'La fecha de despacho es obligatoria.',
            '*.usuario.required' => 'El usuario es obligatorio.',
            '*.usuario.string' => 'El usuario debe ser un texto (email o nickname).',
            '*.codigo_de_producto.required' => 'El código de producto es obligatorio.',
            '*.cantidad.required' => 'La cantidad es obligatoria.',
            '*.cantidad.integer' => 'La cantidad debe ser un número entero.',
            '*.cantidad.min' => 'La cantidad debe ser al menos 1.',
            '*.precio_neto.numeric' => 'El precio neto debe ser un número.',
            '*.parcialmente_programado.in' => 'El campo parcialmente programado debe tener un valor válido (0, 1, true, false, si, no).',
            '*.precio_transporte.numeric' => 'El precio de transporte debe ser un número.',
            '*.precio_transporte.min' => 'El precio de transporte no puede ser negativo.',
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
                        Carbon::createFromFormat('d/m/Y', $row['fecha_de_orden']);
                    } catch (\Exception $e) {
                        $validator->errors()->add(
                            "{$index}.fecha_de_orden",
                            'El formato de la fecha de orden debe ser DD/MM/YYYY.'
                        );
                    }
                }

                if (!empty($row['fecha_de_despacho'])) {
                    try {
                        Carbon::createFromFormat('d/m/Y', $row['fecha_de_despacho']);
                    } catch (\Exception $e) {
                        $validator->errors()->add(
                            "{$index}.fecha_de_despacho",
                            'El formato de la fecha de despacho debe ser DD/MM/YYYY.'
                        );
                    }
                }

                // Validar que el usuario exista (por email o nickname)
                if (!empty($row['usuario'])) {
                    $userExists = $this->findUserByEmailOrNickname($row['usuario']);
                    if (!$userExists) {
                        $validator->errors()->add(
                            "{$index}.usuario",
                            'El usuario especificado no existe (buscado por email o nickname).'
                        );
                    }
                }

                // Validar que el código de producto exista
                if (!empty($row['codigo_de_producto'])) {
                    $productCode = $row['codigo_de_producto'];
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
            // Usar el primer registro para obtener datos de la orden
            $firstRow = $rows->first();
            $idOrden = $firstRow['id_orden'] ?? null;

            Log::info('Procesando orden desde Excel', [
                'order_number' => $orderNumber,
                'id_orden' => $idOrden,
                'precio_transporte' => $firstRow['precio_transporte'] ?? 'NO DEFINIDO',
                'tipo_precio_transporte' => gettype($firstRow['precio_transporte'] ?? null),
                'todas_las_claves' => is_array($firstRow) ? array_keys($firstRow) : $firstRow->keys()->toArray()
            ]);

            // Determinar si existe la combinación id + código
            $existingOrder = $this->findOrderByIdAndNumber($idOrden, $orderNumber);

            // Si ambos campos están vacíos, crear orden completamente nueva
            if (empty($idOrden) && empty($orderNumber)) {
                $this->createNewOrder($rows);
                return;
            }

            // Si la combinación id + código existe, modificar la orden existente
            if ($existingOrder) {
                $processKey = "{$idOrden}_{$orderNumber}";

                // Si este pedido ya fue procesado, verificar si hay cambios
                if (isset($this->processedOrders[$processKey])) {
                    $processedData = $this->processedOrders[$processKey];

                    Log::info('Verificando cambios en pedido ya procesado', [
                        'order_id' => $existingOrder->id,
                        'order_number' => $orderNumber,
                        'precio_transporte_procesado' => $processedData['dispatch_cost'],
                        'precio_transporte_actual' => $firstRow['precio_transporte'] ?? '0.00',
                        'son_iguales' => $processedData['dispatch_cost'] === ($firstRow['precio_transporte'] ?? '0.00')
                    ]);

                    // Verificar si hay cambios en los campos de la orden
                    $orderDataChanged =
                        $processedData['status'] !== $firstRow['estado'] ||
                        $processedData['created_at'] !== $firstRow['fecha_de_orden'] ||
                        $processedData['dispatch_date'] !== $firstRow['fecha_de_despacho'] ||
                        $processedData['user_email'] !== $firstRow['usuario'] ||
                        $processedData['dispatch_cost'] !== ($firstRow['precio_transporte'] ?? '0.00');

                    Log::info('Resultado verificación de cambios', [
                        'order_id' => $existingOrder->id,
                        'orderDataChanged' => $orderDataChanged
                    ]);

                    // Si no hay cambios, solo procesar las líneas de pedido
                    if (!$orderDataChanged) {
                        Log::info('No hay cambios en el pedido, solo procesando líneas', [
                            'order_id' => $existingOrder->id
                        ]);
                        $this->processOrderLines($processedData['order_id'], $rows);
                        return;
                    }
                }
                
                // Actualizar orden existente
                $this->updateExistingOrder($existingOrder, $rows);
                return;
            }

            // En cualquier otro caso (solo uno de los campos coincide, o ninguno), crear nueva orden
            $this->createNewOrder($rows);
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
     * Update existing order with new data
     * 
     * @param Order $order
     * @param Collection $rows
     */
    private function updateExistingOrder(Order $order, Collection $rows)
    {
        try {
            $firstRow = $rows->first();

            // Convertir el estado a su valor numérico correspondiente
            $status = $this->convertStatusToValue($firstRow['estado']);

            // Obtener ID del usuario por email o nickname
            $user = $this->findUserByEmailOrNickname($firstRow['usuario']);
            if (!$user) {
                throw new \Exception("El usuario especificado no existe: {$firstRow['usuario']}");
            }

            // Formatear fechas
            $createdAt = $this->formatDate($firstRow['fecha_de_orden']);
            $dispatchDate = $this->formatDate($firstRow['fecha_de_despacho']);

            // Calcular dispatch_cost (multiplicar por 100 para convertir a centavos)
            $dispatchCost = 0;
            if (isset($firstRow['precio_transporte']) && is_numeric($firstRow['precio_transporte'])) {
                $dispatchCost = (int) ($firstRow['precio_transporte'] * 100);
            }

            Log::info('Actualizando orden existente', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'precio_transporte_excel' => $firstRow['precio_transporte'] ?? 'NO EXISTE',
                'dispatch_cost_calculado' => $dispatchCost,
                'dispatch_cost_anterior' => $order->dispatch_cost
            ]);

            // Actualizar la orden
            $order->update([
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate,
                'dispatch_cost' => $dispatchCost
            ]);

            Log::info('Orden actualizada', [
                'order_id' => $order->id,
                'dispatch_cost_nuevo' => $order->fresh()->dispatch_cost
            ]);

            // Si la fecha de creación es diferente, actualizar manualmente
            if ($order->created_at->format('Y-m-d') !== Carbon::parse($createdAt)->format('Y-m-d')) {
                $order->created_at = $createdAt;
                $order->save();
            }

            // Guardar los datos procesados para comparaciones futuras
            $processKey = "{$order->id}_{$order->order_number}";
            $this->processedOrders[$processKey] = [
                'order_id' => $order->id,
                'status' => $firstRow['estado'],
                'created_at' => $firstRow['fecha_de_orden'],
                'dispatch_date' => $firstRow['fecha_de_despacho'],
                'user_email' => $firstRow['usuario'],
                'dispatch_cost' => $firstRow['precio_transporte'] ?? '0.00'
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
                'update_order_' . $order->id,
                'ImportProcess'
            );

            Log::error("Error actualizando pedido existente", [
                'order_id' => $order->id,
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

            // Obtener ID del usuario por email o nickname
            $user = $this->findUserByEmailOrNickname($firstRow['usuario']);
            if (!$user) {
                throw new \Exception("El usuario especificado no existe: {$firstRow['usuario']}");
            }

            // Formatear fechas
            $createdAt = $this->formatDate($firstRow['fecha_de_orden']);
            $dispatchDate = $this->formatDate($firstRow['fecha_de_despacho']);

            // Calcular dispatch_cost (multiplicar por 100 para convertir a centavos)
            $dispatchCost = 0;
            if (isset($firstRow['precio_transporte']) && is_numeric($firstRow['precio_transporte'])) {
                $dispatchCost = (int) ($firstRow['precio_transporte'] * 100);
            }

            Log::info('Creando orden nueva con order_number', [
                'order_number' => $orderNumber,
                'precio_transporte_excel' => $firstRow['precio_transporte'] ?? 'NO EXISTE',
                'dispatch_cost_calculado' => $dispatchCost
            ]);

            // Crear la orden con el order_number específico
            $order = Order::create([
                'order_number' => $orderNumber,
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate,
                'created_at' => $createdAt,
                'dispatch_cost' => $dispatchCost
            ]);

            Log::info('Orden creada con order_number', [
                'order_id' => $order->id,
                'dispatch_cost_guardado' => $order->dispatch_cost
            ]);

            // Guardar los datos procesados para comparaciones futuras
            $this->processedOrders[$orderNumber] = [
                'order_id' => $order->id,
                'status' => $firstRow['estado'],
                'created_at' => $firstRow['fecha_de_orden'],
                'dispatch_date' => $firstRow['fecha_de_despacho'],
                'user_email' => $firstRow['usuario'],
                'dispatch_cost' => $firstRow['precio_transporte'] ?? '0.00'
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

            // Obtener ID del usuario por email o nickname
            $user = $this->findUserByEmailOrNickname($firstRow['usuario']);
            if (!$user) {
                throw new \Exception("El usuario especificado no existe: {$firstRow['usuario']}");
            }

            // Formatear fechas
            $createdAt = $this->formatDate($firstRow['fecha_de_orden']);
            $dispatchDate = $this->formatDate($firstRow['fecha_de_despacho']);

            // Calcular dispatch_cost (multiplicar por 100 para convertir a centavos)
            $dispatchCost = 0;
            if (isset($firstRow['precio_transporte']) && is_numeric($firstRow['precio_transporte'])) {
                $dispatchCost = (int) ($firstRow['precio_transporte'] * 100);
            }

            Log::info('Creando orden nueva', [
                'precio_transporte_excel' => $firstRow['precio_transporte'] ?? 'NO EXISTE',
                'dispatch_cost_calculado' => $dispatchCost
            ]);

            // Crear la orden (el order_number se generará automáticamente en el modelo)
            $order = Order::create([
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate,
                'created_at' => $createdAt,
                'dispatch_cost' => $dispatchCost
            ]);

            Log::info('Orden creada', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'dispatch_cost_guardado' => $order->dispatch_cost
            ]);

            // Guardar los datos procesados para comparaciones futuras usando el order_number como clave
            $this->processedOrders[$order->order_number] = [
                'order_id' => $order->id,
                'status' => $firstRow['estado'],
                'created_at' => $firstRow['fecha_de_orden'],
                'dispatch_date' => $firstRow['fecha_de_despacho'],
                'user_email' => $firstRow['usuario'],
                'dispatch_cost' => $firstRow['precio_transporte'] ?? '0.00'
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
            // Get the order to preserve dispatch_cost
            $order = Order::find($orderId);
            $preservedDispatchCost = $order->dispatch_cost;

            Log::info('Procesando líneas de pedido', [
                'order_id' => $orderId,
                'dispatch_cost_a_preservar' => $preservedDispatchCost
            ]);

            // Eliminar las líneas de pedido existentes
            OrderLine::where('order_id', $orderId)->delete();

            // Crear nuevas líneas de pedido
            foreach ($rows as $row) {
                // Obtener código de producto sin limpiar comillas
                $productCode = $row['codigo_de_producto'];

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

            // Restore dispatch_cost after processing lines (events may have changed it)
            $order->refresh();
            if ($order->dispatch_cost !== $preservedDispatchCost) {
                Log::warning('dispatch_cost fue modificado por eventos, restaurando valor', [
                    'order_id' => $orderId,
                    'valor_anterior' => $preservedDispatchCost,
                    'valor_modificado' => $order->dispatch_cost
                ]);
                $order->update(['dispatch_cost' => $preservedDispatchCost]);
            }

            Log::info("Líneas de pedido procesadas con éxito", [
                'order_id' => $orderId,
                'lines_count' => $rows->count(),
                'dispatch_cost_final' => $order->fresh()->dispatch_cost
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
