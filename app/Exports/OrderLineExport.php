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
        'empresa' => 'Empresa',
        'nombre_fantasia' => 'Nombre Fantasía',
        'cliente' => 'Cliente',
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
        'precio_transporte' => 'Precio Transporte'
    ];

    private $exportProcessId;
    private $orderLineIds;
    private $lastOrderId = null;
    private $orderItemCounts = [];
    private $orderRowSpans = [];

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
            'product.category'
        ])->whereIn('id', $this->orderLineIds);
    }

    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * Count items for a specific order in the current export
     */
    private function countItemsInExport(int $orderId): int
    {
        $count = 0;
        foreach ($this->orderLineIds as $lineId) {
            $orderLine = OrderLine::find($lineId);
            if ($orderLine && $orderLine->order_id == $orderId) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Apply merge cells for transport price column
     */
    private function applyTransportPriceMerge(Worksheet $sheet, int $lastRow)
    {
        // Identify order groups and apply merge
        $currentOrderId = '';
        $orderStartRow = 0;
        $orderGroups = [];

        // Loop through all rows to identify order groups
        for ($row = 2; $row <= $lastRow; $row++) {
            $orderId = $sheet->getCell('A' . $row)->getValue(); // ID Orden column
            
            // If order changes or it's the last row
            if ($orderId !== $currentOrderId || $row === $lastRow) {
                // Save previous order group info
                if ($orderStartRow > 0) {
                    $endRow = ($row === $lastRow && $orderId === $currentOrderId) ? $row : $row - 1;
                    $transportValue = $sheet->getCell('S' . $orderStartRow)->getValue();
                    
                    
                    $orderGroups[] = [
                        'order_id' => $currentOrderId,
                        'start' => $orderStartRow,
                        'end' => $endRow,
                        'transport_value' => $transportValue
                    ];
                }
                
                // Start new order group
                $currentOrderId = $orderId;
                $orderStartRow = $row;
            }
        }

        // Apply merges and styles to transport price column (S)
        foreach ($orderGroups as $group) {
            
            // Apply styling to all transport price cells with any numeric value (including 0)
            // Check for numeric values or the string "0.00"
            if (is_numeric($group['transport_value']) || $group['transport_value'] === '0.00') {
                // If multiple rows, apply merge
                if ($group['start'] < $group['end']) {
                    $sheet->mergeCells("S{$group['start']}:S{$group['end']}");
                    
                    // Apply alignment for merged cells
                    $sheet->getStyle("S{$group['start']}:S{$group['end']}")
                        ->getAlignment()
                        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
                        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                }
                
                // Apply styling to the range (single cell or merged)
                $range = $group['start'] < $group['end'] ? "S{$group['start']}:S{$group['end']}" : "S{$group['start']}";
                
                
                $this->applyTransportPriceStyle($sheet, $range);
            }
        }
    }

    /**
     * Apply special visual styling for transport price cells
     */
    private function applyTransportPriceStyle(Worksheet $sheet, string $range)
    {
        $transportPriceStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '2F5597']
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E8F4F8'] // Light blue background
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '4472C4']
                ]
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ]
        ];

        $sheet->getStyle($range)->applyFromArray($transportPriceStyle);
    }

    public function map($orderLine): array
    {
        try {
            $order = $orderLine->order;
            $user = $order->user ?? null;
            $company = $user->company ?? null;
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

            // Check if this is the first line of a new order
            $isFirstInOrder = ($this->lastOrderId !== $order->id);
            if ($isFirstInOrder) {
                $this->lastOrderId = $order->id;
                // Count items for this order in current export
                $this->orderItemCounts[$order->id] = $this->countItemsInExport($order->id);
                
                
                // Save info for rowspan (will be updated in AfterSheet)
                if ($this->orderItemCounts[$order->id] > 1) {
                    $this->orderRowSpans[$order->id] = [
                        'start_row' => 0, // Will be updated in AfterSheet
                        'count' => $this->orderItemCounts[$order->id]
                    ];
                }
            }

            // Calculate transport price value
            // Special handling for zero values to ensure they're written to Excel
            $transportPriceValue = '';
            if ($isFirstInOrder) {
                if ($order->dispatch_cost !== null) {
                    $calculatedValue = $order->dispatch_cost / 100;
                    // FORCE writing zero as a numeric string to prevent filtering
                    if ($calculatedValue == 0) {
                        $transportPriceValue = '0.00';  // String zero to ensure it's written
                    } else {
                        $transportPriceValue = number_format($calculatedValue, 2, '.', '');  // Format as string with 2 decimals
                    }
                } else {
                    $transportPriceValue = '0.00';  // String zero for null dispatch_cost
                }
            }
            
            
            // Map data using the same keys expected by the importer
            return [
                'id_orden' => $order ? $order->id : null,
                'codigo_de_pedido' => $codigoPedido,
                'estado' => $estadoOrden,
                'fecha_de_orden' => $fechaOrden,
                'fecha_de_despacho' => $fechaDespacho,
                'empresa' => $company ? $company->name : null,
                'nombre_fantasia' => $company ? $company->fantasy_name : null,
                'cliente' => $user ? $user->name : null,
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
                'precio_transporte' => $transportPriceValue
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
                
                // Apply merge logic for transport price column (Column S)
                $this->applyTransportPriceMerge($sheet, $lastRow);
                
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSED]);
            },
        ];
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