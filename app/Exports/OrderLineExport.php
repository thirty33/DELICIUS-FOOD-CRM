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
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
    ShouldQueue,
    WithChunkReading
{
    use Exportable;

    private $headers = [
        'codigo_pedido' => 'Código de Pedido',
        'estado_orden' => 'Estado Pedido',
        'fecha_orden' => 'Fecha de Orden',
        'fecha_despacho' => 'Fecha de Despacho',
        'usuario' => 'Usuario',
        'codigo_producto' => 'Código de Producto',
        'cantidad' => 'Cantidad',
        'precio_unitario' => 'Precio Unitario',
        'parcialmente_programado' => 'Parcialmente Programado'
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
        return OrderLine::with(['order.user', 'order.user.company.priceList', 'product'])
            ->whereIn('id', $this->orderLineIds);
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function map($orderLine): array
    {
        try {
            $order = $orderLine->order;

            // Formatear las fechas con comilla inicial
            $fechaOrden = '';
            if ($order && $order->created_at) {
                $fechaOrden = "'" . $order->created_at->format('d/m/Y');
            }

            $fechaDespacho = '';
            if ($order && $order->dispatch_date) {
                $fechaDespacho = "'" . Carbon::parse($order->dispatch_date)->format('d/m/Y');
            }

            // Obtener el estado formateado del pedido
            $estadoOrden = null;
            if ($order && $order->status) {
                try {
                    $estadoOrden = OrderStatus::from($order->status)->getLabel();
                } catch (\ValueError $e) {
                    Log::warning('Estado de orden desconocido', [
                        'order_id' => $order->id,
                        'status' => $order->status
                    ]);
                    $estadoOrden = $order->status;
                }
            }

            return [
                'codigo_pedido' => $order ? $order->id : null,
                'estado_orden' => $estadoOrden,
                'fecha_orden' => $fechaOrden,
                'fecha_despacho' => $fechaDespacho,
                'usuario' => $order && $order->user ? $order->user->email : null,
                'codigo_producto' => $orderLine->product ? $orderLine->product->code : null,
                'cantidad' => $orderLine->quantity,
                'precio_unitario' => '$' . number_format($orderLine->unit_price / 100, 2, '.', ','),
                'parcialmente_programado' => $orderLine->partially_scheduled ? '1' : '0'
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando línea de orden para exportación', [
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

    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                ExportProcess::where('id', $this->exportProcessId)
                    ->update(['status' => ExportProcess::STATUS_PROCESSING]);
            },
            AfterSheet::class => function (AfterSheet $event) {
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

        Log::error('Error en exportación de Order Lines', [
            'export_process_id' => $this->exportProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
