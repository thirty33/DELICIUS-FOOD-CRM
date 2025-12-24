<?php

namespace App\Imports;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\ImportProcess;
use App\Enums\OrderStatus;
use App\Enums\OrderImportValidationMessage;
use App\Classes\ErrorManagment\ExportErrorHandler;
use App\Classes\Orders\Validations\OrderStatusValidation;
use App\Classes\Orders\Validations\MaxOrderAmountValidation;
use App\Events\OrderLineQuantityReducedBelowProduced;
use App\Repositories\ImportOrderLineTrackingRepository;
use App\Repositories\OrderRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
    private $currentExcelRowNumber = 0;
    private ImportOrderLineTrackingRepository $trackingRepository;

    /**
     * Header mapping between Excel file and internal system.
     * KEYS are the Excel headers as converted by Laravel Excel (slug format).
     * VALUES are internal field names for processing.
     *
     * Note: Laravel Excel converts "Código de Empresa" to "codigo_de_empresa" automatically.
     */
    private $headingMap = [
        'id_orden' => 'order_id',
        'codigo_de_pedido' => 'order_number',
        'estado' => 'status',
        'fecha_de_orden' => 'created_at',
        'fecha_de_despacho' => 'dispatch_date',
        'codigo_de_empresa' => 'company_code',                 // Laravel Excel converts "Código de Empresa"
        'empresa' => 'company_name',                           // Informational - not validated
        'codigo_sucursal' => 'branch_code',                    // Laravel Excel converts "Código Sucursal"
        'nombre_fantasia_sucursal' => 'branch_fantasy_name',   // Informational - not validated
        'usuario' => 'user_email',
        'categoria' => 'category_name',                        // Laravel Excel converts "Categoría"
        'codigo_de_producto' => 'product_code',
        'nombre_producto' => 'product_name',                   // Check for "TRANSPORTE" to skip row
        'cantidad' => 'quantity',
        'precio_neto' => 'unit_price',
        'precio_con_impuesto' => 'unit_price_with_tax',
        'precio_total_neto' => 'total_price_net',
        'precio_total_con_impuesto' => 'total_price_with_tax',
        'parcialmente_programado' => 'partially_scheduled'
        // Note: dispatch_cost is calculated internally, not imported from Excel
    ];

    public function __construct(int $importProcessId)
    {
        $this->importProcessId = $importProcessId;
        $this->trackingRepository = new ImportOrderLineTrackingRepository();
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
                // Get affected order IDs BEFORE cleanup (cleanup deletes tracking data)
                $affectedOrderIds = $this->trackingRepository->getAffectedOrderIds($this->importProcessId);

                // Clean up order lines that were not in the import file
                // This triggers surplus events for deleted lines via OrderRepository
                $this->cleanupOldOrderLines();

                // Recalculate ordered_quantity_new for all affected OPs
                // This MUST happen AFTER surplus checks are complete
                $this->recalculateAffectedAdvanceOrders($affectedOrderIds);

                $importProcess = ImportProcess::find($this->importProcessId);

                if ($importProcess->status !== ImportProcess::STATUS_PROCESSED_WITH_ERRORS) {
                    $importProcess->update([
                        'status' => ImportProcess::STATUS_PROCESSED
                    ]);
                }

                // Reset importUserId NOW after all cleanup and surplus events are complete
                OrderLine::$importUserId = null;

                Log::info('Finalizada importación de líneas de pedido', [
                    'process_id' => $this->importProcessId,
                    'import_user_id_reset' => true,
                ]);
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
        // Si tenemos ambos, buscar por ambos (más específico)
        if (!empty($id) && !empty($orderNumber)) {
            return Order::where('id', $id)
                ->where('order_number', $orderNumber)
                ->first();
        }

        // Si solo tenemos order_number, buscar por order_number para evitar duplicados
        if (!empty($orderNumber)) {
            return Order::where('order_number', $orderNumber)->first();
        }

        // Si solo tenemos ID, buscar por ID
        if (!empty($id)) {
            return Order::find($id);
        }

        return null;
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
            '*.estado' => [
                'required',
                Rule::in([
                    // Spanish labels - Primera letra mayúscula
                    OrderStatus::PENDING->getLabel(),      // 'Pendiente'
                    OrderStatus::PROCESSED->getLabel(),    // 'Procesado'
                    OrderStatus::CANCELED->getLabel(),     // 'Cancelado'
                    OrderStatus::PARTIALLY_SCHEDULED->getLabel(),  // 'Parcialmente Agendado'
                    // Spanish labels - Todo en MAYÚSCULAS
                    'PENDIENTE',
                    'PROCESADO',
                    'CANCELADO',
                    'PARCIALMENTE AGENDADO',
                ]),
            ],
            '*.fecha_de_orden' => ['required'],               // Can be string or numeric (Excel serial date)
            '*.fecha_de_despacho' => ['required'],            // Can be string or numeric (Excel serial date)
            '*.codigo_de_empresa' => ['required', 'string'],     // Laravel Excel converts "Código de Empresa"
            '*.codigo_sucursal' => ['required', 'string'],       // Laravel Excel converts "Código Sucursal"
            '*.usuario' => ['required', 'string'],
            '*.codigo_de_producto' => ['required', 'string'],
            '*.cantidad' => ['required', 'integer', 'min:1'],
            '*.precio_neto' => ['nullable', 'numeric'],
            '*.parcialmente_programado' => ['nullable', 'in:0,1,true,false,si,no,yes,no']
            // Note: precio_transporte removed - dispatch_cost calculated internally
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
            '*.id_orden.integer' => OrderImportValidationMessage::ID_ORDEN_INTEGER->value,
            '*.estado.required' => OrderImportValidationMessage::ESTADO_REQUIRED->value,
            '*.estado.in' => OrderImportValidationMessage::ESTADO_INVALID->value,
            '*.fecha_de_orden.required' => OrderImportValidationMessage::FECHA_ORDEN_REQUIRED->value,
            '*.fecha_de_despacho.required' => OrderImportValidationMessage::FECHA_DESPACHO_REQUIRED->value,
            '*.codigo_de_empresa.required' => OrderImportValidationMessage::CODIGO_EMPRESA_REQUIRED->value,
            '*.codigo_de_empresa.string' => OrderImportValidationMessage::CODIGO_EMPRESA_STRING->value,
            '*.codigo_sucursal.required' => OrderImportValidationMessage::CODIGO_SUCURSAL_REQUIRED->value,
            '*.codigo_sucursal.string' => OrderImportValidationMessage::CODIGO_SUCURSAL_STRING->value,
            '*.usuario.required' => OrderImportValidationMessage::USUARIO_REQUIRED->value,
            '*.usuario.string' => OrderImportValidationMessage::USUARIO_STRING->value,
            '*.codigo_de_producto.required' => OrderImportValidationMessage::CODIGO_PRODUCTO_REQUIRED->value,
            '*.cantidad.required' => OrderImportValidationMessage::CANTIDAD_REQUIRED->value,
            '*.cantidad.integer' => OrderImportValidationMessage::CANTIDAD_INTEGER->value,
            '*.cantidad.min' => OrderImportValidationMessage::CANTIDAD_MIN->value,
            '*.precio_neto.numeric' => OrderImportValidationMessage::PRECIO_NETO_NUMERIC->value,
            '*.parcialmente_programado.in' => OrderImportValidationMessage::PARCIALMENTE_PROGRAMADO_IN->value,
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
                // Skip TRANSPORTE rows - they are informational only
                $productName = trim(strtoupper($row['nombre_producto'] ?? ''));
                if ($productName === 'TRANSPORTE') {
                    Log::debug('Skipping validation for TRANSPORTE row', [
                        'index' => $index,
                        'order_id' => $row['id_orden'] ?? null
                    ]);
                    continue;
                }

                // No validamos existencia de order_number, si no existe se creará uno nuevo

                // Validar el formato de fechas (acepta números de Excel o strings DD/MM/YYYY)
                if (!empty($row['fecha_de_orden'])) {
                    // If it's numeric (Excel serial date), it's valid
                    if (!is_numeric($row['fecha_de_orden'])) {
                        // If it's a string, validate DD/MM/YYYY format
                        try {
                            Carbon::createFromFormat('d/m/Y', $row['fecha_de_orden']);
                        } catch (\Exception $e) {
                            $validator->errors()->add(
                                "{$index}.fecha_de_orden",
                                OrderImportValidationMessage::FECHA_ORDEN_FORMAT->value
                            );
                        }
                    }
                }

                if (!empty($row['fecha_de_despacho'])) {
                    // If it's numeric (Excel serial date), it's valid
                    if (!is_numeric($row['fecha_de_despacho'])) {
                        // If it's a string, validate DD/MM/YYYY format
                        try {
                            Carbon::createFromFormat('d/m/Y', $row['fecha_de_despacho']);
                        } catch (\Exception $e) {
                            $validator->errors()->add(
                                "{$index}.fecha_de_despacho",
                                OrderImportValidationMessage::FECHA_DESPACHO_FORMAT->value
                            );
                        }
                    }
                }

                // Validar que el usuario exista (por email o nickname)
                if (!empty($row['usuario'])) {
                    $userExists = $this->findUserByEmailOrNickname($row['usuario']);
                    if (!$userExists) {
                        $validator->errors()->add(
                            "{$index}.usuario",
                            OrderImportValidationMessage::USUARIO_NOT_EXISTS->value
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
                            OrderImportValidationMessage::PRODUCTO_NOT_EXISTS->message(['code' => $productCode])
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
            // Enable import mode to disable OrderLineProductionStatusObserver
            OrderLine::$importMode = true;

            // Set the import user ID for surplus transactions during deletion
            $importProcess = ImportProcess::find($this->importProcessId);
            OrderLine::$importUserId = $importProcess?->user_id;

            Log::info('OrderLinesImport procesando colección', [
                'count' => $rows->count(),
                'import_mode_enabled' => OrderLine::$importMode,
                'import_user_id' => OrderLine::$importUserId,
            ]);

            if ($rows->count() > 0) {
                Log::debug('Muestra de datos:', [
                    'primera_fila' => $rows->first()->toArray(),
                    'columnas' => array_keys($rows->first()->toArray())
                ]);
            }

            // Filter out TRANSPORTE rows (informational only, dispatch_cost is calculated internally)
            $filteredRows = $rows->filter(function ($row) {
                $productName = trim(strtoupper($row['nombre_producto'] ?? ''));
                $isTransportRow = $productName === 'TRANSPORTE';

                if ($isTransportRow) {
                    Log::info('Skipping TRANSPORTE row (informational only)', [
                        'order_id' => $row['id_orden'] ?? null,
                        'order_number' => $row['codigo_de_pedido'] ?? null
                    ]);
                }

                return !$isTransportRow;
            });

            Log::info('Rows after filtering TRANSPORTE', [
                'original_count' => $rows->count(),
                'filtered_count' => $filteredRows->count(),
                'transport_rows_skipped' => $rows->count() - $filteredRows->count()
            ]);

            // Log unique order codes BEFORE grouping to detect invisible characters
            $uniqueOrderCodes = $filteredRows->pluck('codigo_de_pedido')->unique();
            $orderCodeAnalysis = $uniqueOrderCodes->map(function($code) {
                return [
                    'code' => $code,
                    'length' => strlen($code),
                    'hex' => bin2hex($code), // Show hexadecimal representation
                    'trimmed' => trim($code),
                    'trimmed_length' => strlen(trim($code)),
                ];
            })->toArray();

            Log::info('ORDER CODES BEFORE GROUPING - Analysis', [
                'unique_count' => $uniqueOrderCodes->count(),
                'order_codes_analysis' => $orderCodeAnalysis,
            ]);

            // Agrupar filas por código de pedido para procesarlas juntas
            $rowsByOrderNumber = $filteredRows->groupBy('codigo_de_pedido');

            Log::info('ORDER CODES AFTER GROUPING', [
                'total_groups' => $rowsByOrderNumber->count(),
                'group_keys' => $rowsByOrderNumber->keys()->toArray(),
            ]);

            // Process each order independently (each order handles its own transaction)
            foreach ($rowsByOrderNumber as $orderNumber => $orderRows) {
                // Limpiar comilla inicial del código de pedido
                $cleanOrderNumber = $this->cleanQuotedValue($orderNumber);

                Log::info('OrderLinesImport: processing order group', [
                    'original_order_number' => $orderNumber,
                    'clean_order_number' => $cleanOrderNumber,
                    'order_number_type' => gettype($orderNumber),
                    'rows_count' => $orderRows->count(),
                ]);

                $this->processOrderRows($cleanOrderNumber, $orderRows);
            }
        } catch (\Exception $e) {
            Log::error('Error general en la importación', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'excel_row' => $this->currentExcelRowNumber,
            ]);

            // Manually call logImportError with the row number
            // Since we're catching the exception, onError() won't be automatically triggered
            $this->logImportError(
                $this->currentExcelRowNumber,
                'general_validation',
                [$e->getMessage()],
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        } finally {
            // Disable import mode but keep importUserId until AfterImport completes
            // (cleanup in AfterImport needs importUserId for surplus transactions)
            OrderLine::$importMode = false;
            Log::info('OrderLinesImport: import mode disabled (importUserId kept for AfterImport)', [
                'import_mode_enabled' => OrderLine::$importMode,
                'import_user_id' => OrderLine::$importUserId,
            ]);
        }
    }

    /**
     * Process all rows for a single order
     * Each order is processed in its own transaction for isolation
     *
     * @param string|int $orderNumber
     * @param Collection $rows
     */
    private function processOrderRows($orderNumber, Collection $rows)
    {
        // Track the missing order
        $isMissingOrder = ($orderNumber === '20251111597579' || $orderNumber === 20251111597579);

        if ($isMissingOrder) {
            Log::critical('MISSING ORDER 20251111597579 - Starting processOrderRows', [
                'order_number' => $orderNumber,
                'order_number_type' => gettype($orderNumber),
                'rows_count' => $rows->count(),
            ]);
        }

        // TDD: Calculate Excel row number for error reporting
        // Laravel Excel uses WithHeadingRow, so row 1 is headers, data starts at row 2
        // rows->keys() gives us 0-indexed collection keys
        // We need the last row number: last_key + 2 (+1 for header, +1 for 0-index)
        $lastRowNumber = $rows->keys()->last() ?? 0;
        $excelRowNumber = $lastRowNumber + 2;

        // TDD: Set current Excel row number for error reporting in onError()
        $this->currentExcelRowNumber = $excelRowNumber;

        // TDD LOG: Debug row numbers for error reporting
        Log::debug('TDD: processOrderRows - row number analysis', [
            'order_number' => $orderNumber,
            'rows_count' => $rows->count(),
            'rows_keys' => $rows->keys()->toArray(),
            'first_key' => $rows->keys()->first(),
            'last_key' => $lastRowNumber,
            'calculated_excel_row_number' => $excelRowNumber,
        ]);

        // Start a transaction for this specific order
        DB::beginTransaction();

        try {
            // Usar el primer registro para obtener datos de la orden
            $firstRow = $rows->first();
            $idOrden = $firstRow['id_orden'] ?? null;

            if ($isMissingOrder) {
                Log::critical('MISSING ORDER 20251111597579 - First row data', [
                    'id_orden' => $idOrden,
                    'usuario' => $firstRow['usuario'] ?? null,
                    'estado' => $firstRow['estado'] ?? null,
                    'fecha_despacho' => $firstRow['fecha_de_despacho'] ?? null,
                ]);
            }

            Log::info('Procesando orden desde Excel', [
                'order_number' => $orderNumber,
                'id_orden' => $idOrden,
                'company_code' => $firstRow['codigo_empresa'] ?? null,
                'branch_code' => $firstRow['codigo_sucursal'] ?? null
            ]);

            // Determinar si existe la combinación id + código
            $existingOrder = $this->findOrderByIdAndNumber($idOrden, $orderNumber);

            if ($isMissingOrder) {
                Log::critical('MISSING ORDER 20251111597579 - After findOrderByIdAndNumber', [
                    'existing_order_found' => $existingOrder ? 'YES (ID: ' . $existingOrder->id . ')' : 'NO',
                    'id_orden' => $idOrden,
                    'order_number' => $orderNumber,
                ]);
            }

            // Si ambos campos están vacíos, crear orden completamente nueva sin order_number
            if (empty($idOrden) && empty($orderNumber)) {
                if ($isMissingOrder) {
                    Log::critical('MISSING ORDER 20251111597579 - Both empty, creating new order without number');
                }
                $this->createNewOrder($rows);
                DB::commit();
                return;
            }

            // Si la combinación id + código existe, modificar la orden existente
            if ($existingOrder) {
                if ($isMissingOrder) {
                    Log::critical('MISSING ORDER 20251111597579 - Existing order found, will update');
                }
                $processKey = "{$idOrden}_{$orderNumber}";

                // Si este pedido ya fue procesado, verificar si hay cambios
                if (isset($this->processedOrders[$processKey])) {
                    $processedData = $this->processedOrders[$processKey];

                    // Verificar si hay cambios en los campos de la orden
                    $orderDataChanged =
                        $processedData['status'] !== $firstRow['estado'] ||
                        $processedData['created_at'] !== $firstRow['fecha_de_orden'] ||
                        $processedData['dispatch_date'] !== $firstRow['fecha_de_despacho'] ||
                        $processedData['user_email'] !== $firstRow['usuario'];

                    if (!$orderDataChanged) {
                        Log::info('No hay cambios en el pedido, solo procesando líneas', [
                            'order_id' => $existingOrder->id
                        ]);
                        $this->processOrderLines($processedData['order_id'], $rows);
                        DB::commit();
                        return;
                    }
                }

                // Actualizar orden existente
                $this->updateExistingOrder($existingOrder, $rows);
                DB::commit();
                return;
            }

            // Si hay order_number pero no existe en la BD, crear nueva orden con ese número
            if (!empty($orderNumber)) {
                if ($isMissingOrder) {
                    Log::critical('MISSING ORDER 20251111597579 - Will create new order with number');
                }
                $this->createNewOrderWithNumber($orderNumber, $rows);
                DB::commit();
                if ($isMissingOrder) {
                    Log::critical('MISSING ORDER 20251111597579 - Successfully committed');
                }
                return;
            }

            // Si solo hay id_orden pero no order_number, crear orden nueva sin número específico
            if ($isMissingOrder) {
                Log::critical('MISSING ORDER 20251111597579 - Will create new order without specific number');
            }
            $this->createNewOrder($rows);
            DB::commit();

        } catch (\Exception $e) {
            // Rollback this specific order's transaction
            DB::rollBack();

            if ($isMissingOrder) {
                Log::critical('MISSING ORDER 20251111597579 - EXCEPTION CAUGHT', [
                    'error_message' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                ]);
            }

            Log::error("Error procesando pedido {$orderNumber} - Rollback ejecutado", [
                'error' => $e->getMessage(),
                'order_number' => $orderNumber,
                'excel_row' => $this->currentExcelRowNumber,
            ]);

            // Manually call logImportError with the row number
            // Since we're catching the exception, onError() won't be automatically triggered
            $this->logImportError(
                $this->currentExcelRowNumber,
                'order_' . $orderNumber,
                [$e->getMessage()],
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'order_number' => $orderNumber,
                ]
            );

            // DO NOT re-throw - allow processing to continue with next order
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

            Log::info('Actualizando orden existente', [
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);

            // Actualizar la orden (dispatch_cost will be calculated by the model)
            $order->update([
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate
            ]);

            // Si la fecha de creación es diferente, actualizar manualmente
            if ($order->created_at->format('Y-m-d') !== Carbon::parse($createdAt)->format('Y-m-d')) {
                $order->created_at = $createdAt;
                $order->save();
            }

            // Process order lines (tracking will happen inside processOrderLines)
            $this->processOrderLines($order->id, $rows);

            // Guardar los datos procesados para comparaciones futuras
            // AFTER processOrderLines() so first chunk can be detected
            $processKey = "{$order->id}_{$order->order_number}";
            $this->processedOrders[$processKey] = [
                'order_id' => $order->id,
                'status' => $firstRow['estado'],
                'created_at' => $firstRow['fecha_de_orden'],
                'dispatch_date' => $firstRow['fecha_de_despacho'],
                'user_email' => $firstRow['usuario']
            ];

            // Execute validation chain
            $this->executeValidationChain($order, $this->buildImportValidationChain());

            Log::info("Pedido actualizado con éxito", [
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
        } catch (\Exception $e) {
            Log::error("Error actualizando pedido existente", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'excel_row' => $this->currentExcelRowNumber,
            ]);

            // Re-throw to trigger rollback in processOrderRows
            // The error will be logged by processOrderRows catch block
            throw $e;
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

            Log::info('Creando orden nueva con order_number', [
                'order_number' => $orderNumber,
                'order_number_type' => gettype($orderNumber),
                'order_number_length' => strlen($orderNumber),
                'user_id' => $user->id,
                'user_nickname' => $user->nickname,
            ]);

            // Crear la orden con el order_number específico (dispatch_cost will be calculated by the model)
            $order = Order::create([
                'order_number' => $orderNumber,
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate,
                'created_at' => $createdAt
            ]);

            Log::info('Orden creada con order_number', [
                'order_id' => $order->id,
                'order_number_saved' => $order->order_number,
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

            // Execute validation chain
            $this->executeValidationChain($order, $this->buildImportValidationChain());

            Log::info("Pedido creado con order_number específico", [
                'order_id' => $order->id,
                'order_number' => $orderNumber
            ]);
        } catch (\Exception $e) {
            Log::error("Error creando nuevo pedido con order_number específico", [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
                'excel_row' => $this->currentExcelRowNumber,
            ]);

            // Re-throw to trigger rollback in processOrderRows
            // The error will be logged by processOrderRows catch block
            throw $e;
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

            Log::info('Creando orden nueva');

            // Crear la orden (el order_number se generará automáticamente en el modelo)
            // dispatch_cost will be calculated by the model
            $order = Order::create([
                'status' => $status,
                'user_id' => $user->id,
                'dispatch_date' => $dispatchDate,
                'created_at' => $createdAt
            ]);

            Log::info('Orden creada', [
                'order_id' => $order->id,
                'order_number' => $order->order_number
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

            // Execute validation chain
            $this->executeValidationChain($order, $this->buildImportValidationChain());

            Log::info("Pedido creado con éxito", [
                'order_id' => $order->id,
                'order_number' => $order->order_number
            ]);
        } catch (\Exception $e) {
            Log::error("Error creando nuevo pedido", [
                'error' => $e->getMessage(),
                'excel_row' => $this->currentExcelRowNumber,
            ]);

            // Re-throw to trigger rollback in processOrderRows
            // The error will be logged by processOrderRows catch block
            throw $e;
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
            // Get order to log its order_number
            $order = Order::find($orderId);
            $orderNumber = $order->order_number ?? 'UNKNOWN';

            // Log Excel data received for ALL orders
            $excelProducts = $rows->map(function($row) {
                return [
                    'codigo_producto' => $row['codigo_de_producto'],
                    'nombre_producto' => $row['nombre_producto'] ?? 'N/A',
                    'cantidad_excel' => $row['cantidad'],
                    'categoria' => $row['categoria'] ?? 'N/A',
                ];
            })->toArray();

            // Get existing order lines count BEFORE processing
            $existingLinesCount = OrderLine::where('order_id', $orderId)->count();

            Log::info('ORDER LINES IMPORT - Excel data received', [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'excel_rows_count' => $rows->count(),
                'existing_lines_count' => $existingLinesCount,
                'excel_products' => $excelProducts,
            ]);

            // Process lines normally - create new ones and track them
            // Cleanup of old lines will happen in AfterImport event
            $createdLines = [];
            $updatedLines = [];
            $skippedProducts = [];

            // Get OrderRepository for surplus calculation
            $orderRepository = app(OrderRepository::class);

            foreach ($rows as $rowIndex => $row) {
                // Obtener código de producto sin limpiar comillas
                $productCode = $row['codigo_de_producto'];

                // Obtener el producto por código
                $product = Product::where('code', $productCode)->first();
                if (!$product) {
                    $errorMsg = "El producto con código {$productCode} no existe.";

                    Log::error('ORDER LINES IMPORT - Product not found in database', [
                        'order_number' => $orderNumber,
                        'row_index' => $rowIndex,
                        'product_code' => $productCode,
                        'product_name' => $row['nombre_producto'] ?? 'N/A',
                        'quantity_from_excel' => $row['cantidad'],
                        'categoria' => $row['categoria'] ?? 'N/A',
                    ]);

                    $skippedProducts[] = [
                        'code' => $productCode,
                        'name' => $row['nombre_producto'] ?? 'N/A',
                        'quantity' => $row['cantidad'],
                        'reason' => 'Product not found in database',
                    ];

                    throw new \Exception($errorMsg);
                }

                $unitPrice = OrderLine::calculateUnitPrice($product->id, $orderId);

                // Convertir parcialmente programado a booleano
                $partiallyScheduled = $this->convertToBoolean($row['parcialmente_programado'] ?? false);

                // Check if order line exists and capture old quantity for surplus calculation
                $existingOrderLine = OrderLine::where('order_id', $orderId)
                    ->where('product_id', $product->id)
                    ->first();

                $oldQuantity = $existingOrderLine ? (int) $existingOrderLine->quantity : null;
                $newQuantity = (int) $row['cantidad'];

                // Update or create order line
                $orderLine = OrderLine::updateOrCreate(
                    [
                        'order_id' => $orderId,
                        'product_id' => $product->id,
                    ],
                    [
                        'quantity' => $newQuantity,
                        'partially_scheduled' => $partiallyScheduled,
                        'unit_price' => $unitPrice,
                    ]
                );

                // Check for surplus when quantity is reduced (only for existing lines)
                if ($oldQuantity !== null && $newQuantity < $oldQuantity) {
                    $this->checkAndDispatchSurplusEvent(
                        $orderLine,
                        $oldQuantity,
                        $newQuantity,
                        $orderRepository
                    );
                }

                // Track this order line for cleanup
                $this->trackingRepository->track(
                    $this->importProcessId,
                    $orderId,
                    $orderLine->id
                );

                $lineData = [
                    'row_index' => $rowIndex,
                    'product_code' => $productCode,
                    'product_name' => $product->name,
                    'quantity_from_excel' => $row['cantidad'],
                    'quantity_saved_db' => $orderLine->quantity,
                    'unit_price' => $unitPrice,
                    'order_line_id' => $orderLine->id,
                    'old_quantity' => $oldQuantity,
                    'surplus_check' => $oldQuantity !== null && $newQuantity < $oldQuantity,
                ];

                $createdLines[] = $lineData;
            }

            Log::info('ORDER LINES IMPORT - Lines processed', [
                'order_number' => $orderNumber,
                'created_lines' => $createdLines,
                'created_count' => count($createdLines),
                'skipped_products' => $skippedProducts,
                'skipped_count' => count($skippedProducts),
            ]);

            // dispatch_cost will be calculated automatically by the Order model

            // Log order lines details before validation
            $order = Order::with('orderLines.product')->find($orderId);
            $lineDetails = $order->orderLines->map(fn($line) => [
                'product_id' => $line->product_id,
                'product_code' => $line->product->code,
                'product_name' => $line->product->name,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'total_price' => $line->total_price,
            ])->toArray();

            // Calculate chunk vs total comparison
            // NOTE: With chunk processing, a single order may be processed across multiple chunks
            // So we compare this chunk's products vs what's in DB (should be >= chunk size if multi-chunk)
            $chunkProductCount = $rows->count();
            $totalDbLines = $order->orderLines->count();
            $chunkProductCodes = $rows->pluck('codigo_de_producto')->toArray();
            $dbProductCodes = $order->orderLines->pluck('product.code')->toArray();

            // Check if all products from this chunk are in DB
            $missingInDb = array_diff($chunkProductCodes, $dbProductCodes);
            $extraInDb = array_diff($dbProductCodes, $chunkProductCodes);

            Log::info("ORDER LINES IMPORT - Lines processed successfully", [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'chunk_products_count' => $chunkProductCount,
                'total_db_lines_count' => $totalDbLines,
                'order_lines_details' => $lineDetails,
                'order_total_before_validation' => $order->total,
            ]);

            // Final comparison log for ALL orders
            Log::info('ORDER LINES IMPORT - Chunk vs DB comparison', [
                'order_number' => $orderNumber,
                'chunk_product_count' => $chunkProductCount,
                'total_db_lines' => $totalDbLines,
                'is_multi_chunk_order' => $totalDbLines > $chunkProductCount,
                'chunk_products' => $chunkProductCodes,
                'all_db_products' => $dbProductCodes,
                'missing_in_db' => $missingInDb,
                'extra_in_db_from_previous_chunks' => $extraInDb,
                'chunk_fully_imported' => count($missingInDb) === 0,
            ]);
        } catch (\Exception $e) {
            Log::error("Error procesando líneas de pedido para orden {$orderId}", [
                'error' => $e->getMessage(),
                'excel_row' => $this->currentExcelRowNumber,
            ]);

            // Re-throw to trigger rollback in processOrderRows
            // The error will be logged by processOrderRows catch block
            throw $e;
        }
    }

    /**
     * Execute validation chain on an order
     *
     * @param Order $order
     * @param OrderStatusValidation $validationChain
     * @throws Exception
     */
    private function executeValidationChain(Order $order, OrderStatusValidation $validationChain): void
    {
        try {
            // Refresh order to get calculated totals and relations
            $order = $order->fresh(['orderLines.product.category.subcategories']);

            // Get user and date from order
            $user = $order->user;
            $date = Carbon::parse($order->dispatch_date);

            // Log validation context
            Log::info('Starting validation chain for order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $user->id,
                'user_nickname' => $user->nickname,
                'user_validate_min_price' => $user->validate_min_price,
                'branch_id' => $user->branch_id,
                'branch_min_price_order' => $user->branch->min_price_order,
                'order_total' => $order->total,
                'order_lines_count' => $order->orderLines->count(),
                'validation_will_trigger' => $user->validate_min_price && ($user->branch->min_price_order > $order->total),
            ]);

            // Execute the validation chain
            $validationChain->validate($order, $user, $date);

        } catch (Exception $e) {
            // Log validation failure with context
            Log::warning('Order validation failed during import', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
                'validation_chain' => get_class($validationChain),
                'error' => $e->getMessage()
            ]);

            // Re-throw to be handled by caller's error handler
            throw $e;
        }
    }

    /**
     * Build the validation chain for imported orders
     *
     * @return OrderStatusValidation
     */
    private function buildImportValidationChain(): OrderStatusValidation
    {
        // Start with minimum price validation
        $validationChain = new MaxOrderAmountValidation();

        // Future validations can be added here by chaining:
        // $validationChain
        //     ->linkWith(new SubcategoryExclusion())
        //     ->linkWith(new MenuCompositionValidation())
        //     ->linkWith(new MandatoryCategoryValidation())
        //     ->linkWith(new ExactProductCountPerSubcategory());

        return $validationChain;
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
     * Format date from Excel format to database format (YYYY-MM-DD)
     * Handles both string format (DD/MM/YYYY) and Excel serial date numbers
     *
     * @param string|int|float $dateValue
     * @return string
     */
    private function formatDate($dateValue)
    {
        // If it's a numeric value (Excel serial date number)
        if (is_numeric($dateValue)) {
            // Excel serial dates start from 1900-01-01 (serial number 1)
            // Convert Excel serial number to Unix timestamp
            // Excel base date is 1899-12-30 (accounting for Excel's leap year bug)
            $unixTimestamp = ($dateValue - 25569) * 86400;
            return Carbon::createFromTimestamp($unixTimestamp)->format('Y-m-d');
        }

        // If it's already a string in DD/MM/YYYY format
        if (is_string($dateValue) && strpos($dateValue, '/') !== false) {
            return Carbon::createFromFormat('d/m/Y', $dateValue)->format('Y-m-d');
        }

        // If it's a standard date string (Y-m-d or similar)
        try {
            return Carbon::parse($dateValue)->format('Y-m-d');
        } catch (\Exception $e) {
            throw new \Exception("Invalid date format: {$dateValue}");
        }
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
     * Unified error logging function for both onFailure and onError
     *
     * @param int $row Excel row number (0 if not available)
     * @param string $attribute Context or attribute name
     * @param array $errors Array of error messages
     * @param array $values Additional values (can include trace, file, line, etc.)
     */
    private function logImportError(int $row, string $attribute, array $errors, array $values = []): void
    {
        $error = [
            'row' => $row,
            'attribute' => $attribute,
            'errors' => $errors,
            'values' => $values,
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
    }

    /**
     * Handle validation failures
     *
     * @param Failure ...$failures
     */
    public function onFailure(Failure ...$failures)
    {
        Log::info('onFailure triggered', [
            'import_process_id' => $this->importProcessId,
            'total_failures' => count($failures),
        ]);

        foreach ($failures as $failure) {
            // Check if this is the missing order
            $orderNumber = $failure->values()['codigo_de_pedido'] ?? null;
            if ($orderNumber === '20251111597579' || $orderNumber === 20251111597579) {
                Log::critical('MISSING ORDER 20251111597579 DETECTED IN onFailure', [
                    'row' => $failure->row(),
                    'attribute' => $failure->attribute(),
                    'errors' => $failure->errors(),
                    'all_values' => $failure->values(),
                ]);
            }

            // Use unified error logging function
            $this->logImportError(
                $failure->row(),
                $failure->attribute(),
                $failure->errors(),
                $failure->values()
            );

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
     * Clean up old order lines that are not in the import file
     * This runs after all chunks have been processed
     */
    private function cleanupOldOrderLines(): void
    {
        try {
            // Get all order lines that were created/updated during this import
            $trackedOrderLineIds = $this->trackingRepository->getTrackedOrderLineIds($this->importProcessId);

            if (empty($trackedOrderLineIds)) {
                Log::info('No tracked order lines found for cleanup', [
                    'import_process_id' => $this->importProcessId
                ]);
                return;
            }

            // Get distinct order IDs from tracked lines
            $orderIds = $this->trackingRepository->getAffectedOrderIds($this->importProcessId);

            Log::info('Starting cleanup of old order lines', [
                'import_process_id' => $this->importProcessId,
                'tracked_lines_count' => count($trackedOrderLineIds),
                'affected_orders_count' => count($orderIds),
            ]);

            // Delete lines that are NOT in the tracking table using repository
            // This uses individual deletion to trigger observers (for surplus handling)
            $orderRepository = app(OrderRepository::class);
            $totalDeleted = $orderRepository->deleteUnimportedOrderLines($orderIds, $trackedOrderLineIds);

            Log::info('Cleanup completed', [
                'import_process_id' => $this->importProcessId,
                'total_deleted' => $totalDeleted,
            ]);

            // Clean up tracking table
            $this->trackingRepository->cleanup($this->importProcessId);

        } catch (\Exception $e) {
            Log::error('Error during cleanup of old order lines', [
                'import_process_id' => $this->importProcessId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recalculate ordered_quantity_new for all AdvanceOrders affected by this import.
     *
     * This method is called AFTER cleanup to ensure all surplus checks are complete.
     * It dispatches recalculation jobs for each affected product in each affected OP.
     *
     * @param array $orderIds Array of order IDs that were affected by the import
     */
    private function recalculateAffectedAdvanceOrders(array $orderIds): void
    {
        try {
            if (empty($orderIds)) {
                Log::info('No affected orders for recalculation', [
                    'import_process_id' => $this->importProcessId,
                ]);
                return;
            }

            Log::info('Starting recalculation of affected AdvanceOrders', [
                'import_process_id' => $this->importProcessId,
                'affected_order_ids' => $orderIds,
            ]);

            // Use repository to recalculate
            $advanceOrderRepository = app(\App\Repositories\AdvanceOrderRepository::class);
            $advanceOrderRepository->recalculateOrderedQuantityNewForOrders($orderIds);

            Log::info('Completed recalculation of affected AdvanceOrders', [
                'import_process_id' => $this->importProcessId,
            ]);

        } catch (\Exception $e) {
            Log::error('Error during recalculation of AdvanceOrders', [
                'import_process_id' => $this->importProcessId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a surplus event should be dispatched when quantity is reduced during import.
     *
     * This method replicates the logic from OrderLineProductionStatusObserver but
     * is called directly during import since the observer is disabled (importMode=true).
     *
     * Surplus = max(0, producedQuantity - newQuantity)
     *
     * @param OrderLine $orderLine The updated order line
     * @param int $oldQuantity Previous quantity before update
     * @param int $newQuantity New quantity from import
     * @param OrderRepository $orderRepository Repository to get production data
     */
    private function checkAndDispatchSurplusEvent(
        OrderLine $orderLine,
        int $oldQuantity,
        int $newQuantity,
        OrderRepository $orderRepository
    ): void {
        // Get the amount actually produced for this product in this order
        $producedQuantity = $orderRepository->getTotalProducedForProduct(
            $orderLine->order_id,
            $orderLine->product_id
        );

        // Calculate surplus: what was produced minus what is now needed
        $surplus = max(0, $producedQuantity - $newQuantity);

        Log::info('OrderLinesImport: Checking surplus during import', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'produced_quantity' => $producedQuantity,
            'surplus' => $surplus,
        ]);

        if ($surplus > 0) {
            // Get user_id from ImportProcess (the user who initiated the import)
            $importProcess = ImportProcess::find($this->importProcessId);
            $userId = $importProcess?->user_id;

            Log::info('OrderLinesImport: Dispatching surplus event', [
                'order_line_id' => $orderLine->id,
                'surplus' => $surplus,
                'user_id' => $userId,
            ]);

            event(new OrderLineQuantityReducedBelowProduced(
                $orderLine,
                $oldQuantity,
                $newQuantity,
                (int) $producedQuantity,
                $surplus,
                $userId
            ));
        }
    }

    /**
     * Handle import errors
     *
     * @param Throwable $e
     */
    public function onError(Throwable $e)
    {
        Log::critical('onError triggered', [
            'import_process_id' => $this->importProcessId,
            'error_message' => $e->getMessage(),
            'error_class' => get_class($e),
            'current_excel_row_number' => $this->currentExcelRowNumber,
        ]);

        // Clean up tracking table on error to prevent orphaned records
        try {
            $this->trackingRepository->cleanup($this->importProcessId);
        } catch (\Exception $cleanupException) {
            Log::error('Failed to cleanup tracking table after error', [
                'import_process_id' => $this->importProcessId,
                'cleanup_error' => $cleanupException->getMessage()
            ]);
        }

        // Use unified error logging function
        // If currentExcelRowNumber is set (from processOrderRows), use it; otherwise default to 0
        $this->logImportError(
            $this->currentExcelRowNumber,
            'general_error',
            [$e->getMessage()],
            [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        );

        Log::error('Error en importación de líneas de pedido', [
            'import_process_id' => $this->importProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'excel_row' => $this->currentExcelRowNumber,
        ]);
    }
}
