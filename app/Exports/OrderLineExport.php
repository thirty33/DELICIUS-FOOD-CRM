<?php

namespace App\Exports;

use App\Imports\Concerns\OrderLineColumnDefinition;
use App\Models\OrderLine;
use App\Models\Order;
use App\Models\ExportProcess;
use App\Enums\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
// ShouldAutoSize removed - causes timeout on large exports due to calculateColumnWidths()
// use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomQuerySize;
use App\Exports\Concerns\HasChunkAwareness;
use Throwable;

class OrderLineExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    // ShouldAutoSize removed - causes timeout on large exports due to calculateColumnWidths()
    WithStyles,
    WithEvents,
    WithColumnFormatting,
    SkipsEmptyRows,
    ShouldQueue,
    WithChunkReading,
    WithCustomQuerySize
{
    use Exportable, HasChunkAwareness;

    /**
     * Job timeout in seconds (20 minutes).
     *
     * Note: This timeout applies to jobs that inherit from this export.
     * For AppendQueryToSheet jobs, the timeout is also set via middleware().
     *
     * @var int
     */
    public $timeout = 1200;

    private $headers = OrderLineColumnDefinition::COLUMNS;

    private $exportProcessId;
    private $orderLineIdsS3BasePath;
    private $totalChunks;
    private $orderLineIds = null;
    private $totalRecords = 0;

    /**
     * Constructor with backward-compatible signature.
     *
     * To avoid SQS 256KB message size limit and large whereIn queries,
     * IDs can be stored in S3 as chunked files.
     *
     * @param Collection|null $orderLineIds Collection of order line IDs (used for tests, null for S3 mode)
     * @param int $exportProcessId Export process ID for tracking
     * @param string|null $orderLineIdsS3BasePath Optional S3 base path where chunked IDs are stored
     * @param int|null $totalChunks Total number of chunks in S3 (required if using S3 mode)
     * @param int|null $totalIds Total number of IDs (for querySize, optional)
     */
    public function __construct(
        ?Collection $orderLineIds,
        int $exportProcessId,
        ?string $orderLineIdsS3BasePath = null,
        ?int $totalChunks = null,
        ?int $totalIds = null
    ) {
        $this->exportProcessId = $exportProcessId;

        // If S3 base path is provided, use chunked mode
        // Otherwise, store IDs in memory (for tests and small exports)
        if ($orderLineIdsS3BasePath !== null) {
            $this->orderLineIdsS3BasePath = $orderLineIdsS3BasePath;
            $this->totalChunks = $totalChunks ?? 1;
            // Use provided total or calculate based on chunks
            $this->totalRecords = $totalIds ?? ($this->totalChunks * $this->chunkSize());
            Log::info('OrderLineExport: Using chunked S3 storage', [
                'export_process_id' => $exportProcessId,
                's3_base_path' => $orderLineIdsS3BasePath,
                'total_chunks' => $this->totalChunks,
                'total_records' => $this->totalRecords,
            ]);
        } elseif ($orderLineIds !== null) {
            // For tests and backward compatibility: keep IDs in memory
            $this->orderLineIds = $orderLineIds->toArray();
            $this->totalRecords = count($this->orderLineIds);
            Log::info('OrderLineExport: Using in-memory IDs', [
                'export_process_id' => $exportProcessId,
                'ids_count' => $this->totalRecords,
            ]);
        }
    }

    /**
     * Return the total number of records for WithCustomQuerySize.
     *
     * This is used by ChunkAwareQueuedWriter to determine how many jobs to create
     * without calling query()->count() which would fail without IDs loaded.
     *
     * @return int
     */
    public function querySize(): int
    {
        return $this->totalRecords;
    }

    /**
     * Load order line IDs.
     *
     * Returns IDs from memory if available. If using S3 chunks and chunk awareness
     * is available (via HasChunkAwareness trait), loads only the current chunk.
     * Falls back to loading all chunks if chunk awareness is not available.
     *
     * @return array
     */
    private function loadOrderLineIds(): array
    {
        // If IDs are already in memory (test mode or OrderLineChunkedExportService), return them
        if ($this->orderLineIds !== null) {
            return $this->orderLineIds;
        }

        // If using S3 chunks and chunk awareness is available, load only current chunk
        if ($this->orderLineIdsS3BasePath !== null && $this->hasChunkAwareness()) {
            return $this->getIdsForCurrentChunk();
        }

        // Fallback: load all chunks from S3 (original behavior)
        if ($this->orderLineIdsS3BasePath !== null) {
            return $this->loadAllChunks();
        }

        // Fallback to empty array
        return [];
    }

    /**
     * Clean up all temporary S3 chunk files after export completion.
     *
     * @return void
     */
    private function cleanupS3TempFiles(): void
    {
        if ($this->orderLineIdsS3BasePath === null) {
            return;
        }

        try {
            $deletedCount = 0;

            for ($i = 0; $i < $this->totalChunks; $i++) {
                $chunkPath = "{$this->orderLineIdsS3BasePath}-chunk-{$i}.json";

                if (Storage::disk('s3')->exists($chunkPath)) {
                    Storage::disk('s3')->delete($chunkPath);
                    $deletedCount++;
                }
            }

            Log::info('OrderLineExport: Cleaned up S3 temp files', [
                'export_process_id' => $this->exportProcessId,
                's3_base_path' => $this->orderLineIdsS3BasePath,
                'deleted_chunks' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            Log::warning('OrderLineExport: Failed to cleanup S3 temp files', [
                'export_process_id' => $this->exportProcessId,
                's3_base_path' => $this->orderLineIdsS3BasePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function query()
    {
        $ids = $this->loadOrderLineIds();

        return OrderLine::with([
            'order.user',
            'order.user.company',
            'order.user.branch',
            'product.category'
        ])->whereIn('id', $ids);
    }

    public function chunkSize(): int
    {
        // Must match OrderLineExportService::$chunkSize for S3 chunk mode
        // Each S3 chunk file contains 1000 IDs, so each job processes 1000 records
        return 1000;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * This middleware is used by AppendQueryToSheet jobs to set the timeout.
     * Without this, the jobs would use the default queue timeout which may be too short.
     *
     * @return array
     */
    public function middleware(): array
    {
        return [
            new \Illuminate\Queue\Middleware\WithoutOverlapping($this->exportProcessId),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     *
     * @return \DateTime
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(20);
    }

    public function map($orderLine): array
    {
        try {
            $order = $orderLine->order;
            $user = $order->user ?? null;
            $company = $user->company ?? null;
            $branch = $user->branch ?? null; // NEW: Get branch information
            $product = $orderLine->product ?? null;
            $category = $product->category ?? null;

            // Skip if essential data is missing
            if (!$order || !$product) {
                return [];
            }

            // Format dates using the same format as MenuDataExport
            $fechaOrden = '';
            if ($order && $order->created_at) {
                $fechaOrden = $order->created_at->format('d/m/Y');
            }

            $fechaDespacho = '';
            if ($order && $order->dispatch_date) {
                $fechaDespacho = Carbon::parse($order->dispatch_date)->format('d/m/Y');
            }

            // Get formatted order status
            $estadoOrden = null;
            if ($order && $order->status) {
                try {
                    $estadoOrden = OrderStatus::from($order->status)->getLabel();
                } catch (\ValueError $e) {
                    Log::warning('Unknown order status', [
                        'order_id' => $order->id,
                        'status' => $order->status
                    ]);
                    $estadoOrden = $order->status;
                }
            }

            // Order code without quotes (numeric format)
            $codigoPedido = ($order && $order->order_number) ? $order->order_number : null;
            // Product code without quotes (numeric format)
            $codigoProducto = ($product && $product->code) ? $product->code : null;
            
            // Calculate total prices
            $precioTotalNeto = $orderLine->quantity * $orderLine->unit_price;
            $precioTotalConImpuesto = $orderLine->quantity * $orderLine->unit_price_with_tax;

            // OLD: Calculate transport price value for every line (REMOVED - now separate row per order)
            // $transportPriceValue = '';
            // if ($order->dispatch_cost !== null) {
            //     $calculatedValue = $order->dispatch_cost / 100;
            //     if ($calculatedValue == 0) {
            //         $transportPriceValue = '0.00';
            //     } else {
            //         $transportPriceValue = number_format($calculatedValue, 2, '.', '');
            //     }
            // } else {
            //     $transportPriceValue = '0.00';
            // }


            // Map data using keys matching OrderLineColumnDefinition::COLUMNS
            return [
                'id_orden' => $order ? $order->id : null,
                'codigo_de_pedido' => $codigoPedido,
                'estado' => $estadoOrden,
                'fecha_de_orden' => $fechaOrden,
                'fecha_de_despacho' => $fechaDespacho,
                'codigo_de_empresa' => $company ? $company->company_code : null,
                'empresa' => $company ? $company->name : null,
                'codigo_sucursal' => $branch ? $branch->branch_code : null,
                'nombre_fantasia_sucursal' => $branch ? $branch->fantasy_name : null,
                'usuario' => $user ? ($user->email ?: $user->nickname) : null,
                'codigo_de_facturacion_usuario' => $user ? ($user->billing_code ?? '') : '',
                'categoria' => $category ? $category->name : null,
                'codigo_de_producto' => $codigoProducto,
                'codigo_de_facturacion_producto' => $product ? ($product->billing_code ?? '') : '',
                'nombre_producto' => $product ? $product->name : null,
                'cantidad' => $orderLine->quantity,
                'precio_neto' => $orderLine->unit_price / 100,
                'precio_con_impuesto' => $orderLine->unit_price_with_tax / 100,
                'precio_total_neto' => $precioTotalNeto / 100,
                'precio_total_con_impuesto' => $precioTotalConImpuesto / 100,
                'parcialmente_programado' => $orderLine->partially_scheduled ? '1' : '0',
            ];
        } catch (\Exception $e) {
            Log::error('Error mapping order line for export', [
                'export_process_id' => $this->exportProcessId,
                'order_line_id' => $orderLine->id,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function headings(): array
    {
        return array_values($this->headers);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2EFDA']
                ]
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            OrderLineColumnDefinition::columnLetter('codigo_de_pedido') => NumberFormat::FORMAT_NUMBER,
            OrderLineColumnDefinition::columnLetter('codigo_de_producto') => NumberFormat::FORMAT_TEXT,
            OrderLineColumnDefinition::columnLetter('precio_neto') => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,
            OrderLineColumnDefinition::columnLetter('precio_con_impuesto') => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,
            OrderLineColumnDefinition::columnLetter('precio_total_neto') => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,
            OrderLineColumnDefinition::columnLetter('precio_total_con_impuesto') => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,
        ];
    }

    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSING]);
            },
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // NEW: Add transport rows for each order BEFORE cleaning empty rows
                $this->addTransportRows($sheet);

                // Remove empty rows at the end
                $lastRow = $sheet->getHighestRow();
                $lastColumn = $sheet->getHighestColumn();

                // Find actual last row with data
                for ($row = $lastRow; $row > 1; $row--) {
                    $hasData = false;
                    for ($col = 'A'; $col <= $lastColumn; $col++) {
                        if (!empty(trim($sheet->getCell($col . $row)->getValue()))) {
                            $hasData = true;
                            break;
                        }
                    }
                    if ($hasData) {
                        break;
                    }
                }

                // Remove empty rows after the last row with data
                if ($row < $lastRow) {
                    $sheet->removeRow($row + 1, $lastRow - $row);
                    $lastRow = $row; // Update lastRow after removal
                }

                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);

                // Clean up temporary S3 files
                $this->cleanupS3TempFiles();
            },
        ];
    }

    /**
     * Add transport rows for each order in the export.
     *
     * This method reads the exported order lines, identifies unique orders,
     * and adds a "TRANSPORTE" row after the last order line of each order.
     *
     * @param Worksheet $sheet
     * @return void
     */
    protected function addTransportRows(Worksheet $sheet): void
    {
        $lastRow = $sheet->getHighestRow();

        // Resolve column letters from the single source of truth
        $colIdOrden = OrderLineColumnDefinition::columnLetter('id_orden');
        $colCodigoPedido = OrderLineColumnDefinition::columnLetter('codigo_de_pedido');
        $colEstado = OrderLineColumnDefinition::columnLetter('estado');
        $colFechaOrden = OrderLineColumnDefinition::columnLetter('fecha_de_orden');
        $colFechaDespacho = OrderLineColumnDefinition::columnLetter('fecha_de_despacho');
        $colCodigoEmpresa = OrderLineColumnDefinition::columnLetter('codigo_de_empresa');
        $colEmpresa = OrderLineColumnDefinition::columnLetter('empresa');
        $colCodigoSucursal = OrderLineColumnDefinition::columnLetter('codigo_sucursal');
        $colNombreFantasia = OrderLineColumnDefinition::columnLetter('nombre_fantasia_sucursal');
        $colUsuario = OrderLineColumnDefinition::columnLetter('usuario');
        $colBillingUsuario = OrderLineColumnDefinition::columnLetter('codigo_de_facturacion_usuario');
        $colCategoria = OrderLineColumnDefinition::columnLetter('categoria');
        $colCodigoProducto = OrderLineColumnDefinition::columnLetter('codigo_de_producto');
        $colBillingProducto = OrderLineColumnDefinition::columnLetter('codigo_de_facturacion_producto');
        $colNombreProducto = OrderLineColumnDefinition::columnLetter('nombre_producto');
        $colCantidad = OrderLineColumnDefinition::columnLetter('cantidad');
        $colPrecioNeto = OrderLineColumnDefinition::columnLetter('precio_neto');
        $colPrecioImpuesto = OrderLineColumnDefinition::columnLetter('precio_con_impuesto');
        $colTotalNeto = OrderLineColumnDefinition::columnLetter('precio_total_neto');
        $colTotalImpuesto = OrderLineColumnDefinition::columnLetter('precio_total_con_impuesto');
        $colParcial = OrderLineColumnDefinition::columnLetter('parcialmente_programado');

        // Collect order data while reading the sheet
        $ordersData = [];
        $currentRow = 2; // Start after header

        // Read all rows and group by order_id
        while ($currentRow <= $lastRow) {
            $orderId = $sheet->getCell($colIdOrden . $currentRow)->getValue();

            if ($orderId) {
                if (!isset($ordersData[$orderId])) {
                    $ordersData[$orderId] = [
                        'last_row' => $currentRow,
                        'codigo_pedido' => $sheet->getCell($colCodigoPedido . $currentRow)->getValue(),
                        'estado' => $sheet->getCell($colEstado . $currentRow)->getValue(),
                        'fecha_orden' => $sheet->getCell($colFechaOrden . $currentRow)->getValue(),
                        'fecha_despacho' => $sheet->getCell($colFechaDespacho . $currentRow)->getValue(),
                        'codigo_empresa' => $sheet->getCell($colCodigoEmpresa . $currentRow)->getValue(),
                        'empresa' => $sheet->getCell($colEmpresa . $currentRow)->getValue(),
                        'codigo_sucursal' => $sheet->getCell($colCodigoSucursal . $currentRow)->getValue(),
                        'nombre_fantasia_sucursal' => $sheet->getCell($colNombreFantasia . $currentRow)->getValue(),
                        'usuario' => $sheet->getCell($colUsuario . $currentRow)->getValue(),
                    ];
                } else {
                    $ordersData[$orderId]['last_row'] = $currentRow;
                }
            }

            $currentRow++;
        }

        // Now add transport rows in reverse order (to avoid shifting row numbers)
        $orderIds = array_keys($ordersData);
        rsort($orderIds);

        foreach ($orderIds as $orderId) {
            $orderData = $ordersData[$orderId];
            $insertAfterRow = $orderData['last_row'];

            $order = Order::find($orderId);

            if ($order && $order->dispatch_cost !== null && $order->dispatch_cost > 0) {
                $sheet->insertNewRowBefore($insertAfterRow + 1, 1);
                $r = $insertAfterRow + 1;

                $sheet->setCellValue($colIdOrden . $r, $orderId);
                $sheet->setCellValue($colCodigoPedido . $r, $orderData['codigo_pedido']);
                $sheet->setCellValue($colEstado . $r, $orderData['estado']);
                $sheet->setCellValue($colFechaOrden . $r, $orderData['fecha_orden']);
                $sheet->setCellValue($colFechaDespacho . $r, $orderData['fecha_despacho']);
                $sheet->setCellValue($colCodigoEmpresa . $r, $orderData['codigo_empresa']);
                $sheet->setCellValue($colEmpresa . $r, $orderData['empresa']);
                $sheet->setCellValue($colCodigoSucursal . $r, $orderData['codigo_sucursal']);
                $sheet->setCellValue($colNombreFantasia . $r, $orderData['nombre_fantasia_sucursal']);
                $sheet->setCellValue($colUsuario . $r, $orderData['usuario']);
                $sheet->setCellValue($colBillingUsuario . $r, '');
                $sheet->setCellValue($colCategoria . $r, '');
                $sheet->setCellValue($colCodigoProducto . $r, '');
                $sheet->setCellValue($colBillingProducto . $r, '');
                $sheet->setCellValue($colNombreProducto . $r, 'TRANSPORTE');
                $sheet->setCellValue($colCantidad . $r, 1);
                $sheet->setCellValue($colPrecioNeto . $r, $order->dispatch_cost / 100);
                $sheet->setCellValue($colPrecioImpuesto . $r, $order->dispatch_cost / 100);
                $sheet->setCellValue($colTotalNeto . $r, $order->dispatch_cost / 100);
                $sheet->setCellValue($colTotalImpuesto . $r, $order->dispatch_cost / 100);
                $sheet->setCellValue($colParcial . $r, '');
            }
        }
    }

    /**
     * Handle a failed export
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $e): void
    {
        $currentUser = exec('whoami');

        $error = [
            'row' => 0,
            'attribute' => 'export',
            'errors' => [$e->getMessage()],
            'values' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'user' => $currentUser
            ],
        ];

        // Obtener el proceso actual y sus errores existentes
        $exportProcess = ExportProcess::find($this->exportProcessId);
        $existingErrors = $exportProcess->error_log ?? [];

        // Agregar el nuevo error al array existente
        $existingErrors[] = $error;

        // Actualizar el error_log en el ExportProcess
        $exportProcess->update([
            'error_log' => $existingErrors,
            'status' => ExportProcess::STATUS_PROCESSED_WITH_ERRORS
        ]);

        Log::error('Error in Order Lines export', [
            'export_process_id' => $this->exportProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        // Clean up temporary S3 files even on failure
        $this->cleanupS3TempFiles();
    }
}