<?php

namespace App\Exports;

use App\Models\OrderLine;
use App\Models\Order;
use App\Models\ExportProcess;
use App\Enums\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
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
use Throwable;

class OrderLineExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    WithColumnFormatting,
    SkipsEmptyRows,
    ShouldQueue,
    WithChunkReading
{
    use Exportable;

    /**
     * Headers modified to display user-friendly texts while maintaining compatibility
     * with the keys expected by the import system.
     * Keys are the internal names used by the system and values are the visible texts.
     */
    private $headers = [
        'id_orden' => 'ID Orden',
        'codigo_de_pedido' => 'Código de Pedido',
        'estado' => 'Estado',
        'fecha_de_orden' => 'Fecha de Orden',
        'fecha_de_despacho' => 'Fecha de Despacho',
        'codigo_empresa' => 'Código de Empresa', // NEW: Company code
        'empresa' => 'Empresa',
        // 'nombre_fantasia' => 'Nombre Fantasía', // REMOVED: Replaced by branch info
        // 'cliente' => 'Cliente', // REMOVED: Replaced by branch info
        'codigo_sucursal' => 'Código Sucursal', // NEW: Branch code
        'nombre_fantasia_sucursal' => 'Nombre Fantasía Sucursal', // NEW: Branch fantasy name
        'usuario' => 'Usuario',
        'categoria_producto' => 'Categoría',
        'codigo_de_producto' => 'Código de Producto',
        'nombre_producto' => 'Nombre Producto',
        'cantidad' => 'Cantidad',
        'precio_neto' => 'Precio Neto',
        'precio_con_impuesto' => 'Precio con Impuesto',
        'precio_total_neto' => 'Precio Total Neto',
        'precio_total_con_impuesto' => 'Precio Total con Impuesto',
        'parcialmente_programado' => 'Parcialmente Programado',
        // 'precio_transporte' => 'Precio Transporte' // REMOVED: Will be separate row
    ];

    private $exportProcessId;
    private $orderLineIds;

    public function __construct(Collection $orderLineIds, int $exportProcessId)
    {
        $this->orderLineIds = $orderLineIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return OrderLine::with([
            'order.user',
            'order.user.company',
            'order.user.branch', // NEW: Load branch information
            'product.category'
        ])->whereIn('id', $this->orderLineIds);
    }

    public function chunkSize(): int
    {
        return 100;
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


            // Map data using the same keys expected by the importer
            return [
                'id_orden' => $order ? $order->id : null,
                'codigo_de_pedido' => $codigoPedido,
                'estado' => $estadoOrden,
                'fecha_de_orden' => $fechaOrden,
                'fecha_de_despacho' => $fechaDespacho,
                'codigo_empresa' => $company ? $company->company_code : null, // NEW: Company code
                'empresa' => $company ? $company->name : null,
                // OLD FIELDS (commented for validation):
                // 'nombre_fantasia' => $company ? $company->fantasy_name : null,
                // 'cliente' => $user ? $user->name : null,
                'codigo_sucursal' => $branch ? $branch->branch_code : null, // NEW: Branch code
                'nombre_fantasia_sucursal' => $branch ? $branch->fantasy_name : null, // NEW: Branch fantasy name
                'usuario' => $user ? ($user->email ?: $user->nickname) : null,
                'categoria_producto' => $category ? $category->name : null,
                'codigo_de_producto' => $codigoProducto,
                'nombre_producto' => $product ? $product->name : null,
                'cantidad' => $orderLine->quantity,
                'precio_neto' => $orderLine->unit_price / 100,
                'precio_con_impuesto' => $orderLine->unit_price_with_tax / 100,
                'precio_total_neto' => $precioTotalNeto / 100,
                'precio_total_con_impuesto' => $precioTotalConImpuesto / 100,
                'parcialmente_programado' => $orderLine->partially_scheduled ? '1' : '0',
                // OLD FIELD (commented for validation):
                // 'precio_transporte' => $transportPriceValue
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
            'B' => NumberFormat::FORMAT_NUMBER,                     // codigo_de_pedido
            'K' => NumberFormat::FORMAT_TEXT,                       // codigo_de_producto (alfanumérico)
            'N' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,    // precio_neto
            'O' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,    // precio_con_impuesto
            'P' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,    // precio_total_neto
            'Q' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,    // precio_total_con_impuesto
            'S' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED2,    // precio_transporte
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

        // Collect order data while reading the sheet
        $ordersData = [];
        $currentRow = 2; // Start after header

        // Read all rows and group by order_id
        while ($currentRow <= $lastRow) {
            $orderId = $sheet->getCell('A' . $currentRow)->getValue(); // Column A = id_orden

            if ($orderId) {
                if (!isset($ordersData[$orderId])) {
                    // Store order info from first line of this order
                    $ordersData[$orderId] = [
                        'last_row' => $currentRow,
                        'codigo_pedido' => $sheet->getCell('B' . $currentRow)->getValue(),
                        'estado' => $sheet->getCell('C' . $currentRow)->getValue(),
                        'fecha_orden' => $sheet->getCell('D' . $currentRow)->getValue(),
                        'fecha_despacho' => $sheet->getCell('E' . $currentRow)->getValue(),
                        'codigo_empresa' => $sheet->getCell('F' . $currentRow)->getValue(),
                        'empresa' => $sheet->getCell('G' . $currentRow)->getValue(),
                        'codigo_sucursal' => $sheet->getCell('H' . $currentRow)->getValue(),
                        'nombre_fantasia_sucursal' => $sheet->getCell('I' . $currentRow)->getValue(),
                        'usuario' => $sheet->getCell('J' . $currentRow)->getValue(),
                    ];
                } else {
                    // Update last_row for this order
                    $ordersData[$orderId]['last_row'] = $currentRow;
                }
            }

            $currentRow++;
        }

        // Now add transport rows in reverse order (to avoid shifting row numbers)
        $orderIds = array_keys($ordersData);
        rsort($orderIds); // Reverse sort to insert from bottom to top

        foreach ($orderIds as $orderId) {
            $orderData = $ordersData[$orderId];
            $insertAfterRow = $orderData['last_row'];

            // Get the order from database to fetch dispatch_cost
            $order = Order::find($orderId);

            if ($order && $order->dispatch_cost !== null && $order->dispatch_cost > 0) {
                // Insert new row after the last order line
                $sheet->insertNewRowBefore($insertAfterRow + 1, 1);

                // Populate transport row
                $transportRow = $insertAfterRow + 1;

                $sheet->setCellValue('A' . $transportRow, $orderId); // id_orden
                $sheet->setCellValue('B' . $transportRow, $orderData['codigo_pedido']); // codigo_de_pedido
                $sheet->setCellValue('C' . $transportRow, $orderData['estado']); // estado
                $sheet->setCellValue('D' . $transportRow, $orderData['fecha_orden']); // fecha_de_orden
                $sheet->setCellValue('E' . $transportRow, $orderData['fecha_despacho']); // fecha_de_despacho
                $sheet->setCellValue('F' . $transportRow, $orderData['codigo_empresa']); // codigo_empresa
                $sheet->setCellValue('G' . $transportRow, $orderData['empresa']); // empresa
                $sheet->setCellValue('H' . $transportRow, $orderData['codigo_sucursal']); // codigo_sucursal
                $sheet->setCellValue('I' . $transportRow, $orderData['nombre_fantasia_sucursal']); // nombre_fantasia_sucursal
                $sheet->setCellValue('J' . $transportRow, $orderData['usuario']); // usuario
                $sheet->setCellValue('K' . $transportRow, ''); // categoria_producto (empty)
                $sheet->setCellValue('L' . $transportRow, ''); // codigo_de_producto (empty)
                $sheet->setCellValue('M' . $transportRow, 'TRANSPORTE'); // nombre_producto
                $sheet->setCellValue('N' . $transportRow, 1); // cantidad
                $sheet->setCellValue('O' . $transportRow, $order->dispatch_cost / 100); // precio_neto
                $sheet->setCellValue('P' . $transportRow, $order->dispatch_cost / 100); // precio_con_impuesto
                $sheet->setCellValue('Q' . $transportRow, $order->dispatch_cost / 100); // precio_total_neto
                $sheet->setCellValue('R' . $transportRow, $order->dispatch_cost / 100); // precio_total_con_impuesto
                $sheet->setCellValue('S' . $transportRow, ''); // parcialmente_programado (empty)
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
    }
}