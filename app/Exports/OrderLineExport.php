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

    /**
     * Headers modificados para mostrar textos amigables al usuario pero mantener compatibilidad
     * con las claves que espera el sistema importador.
     * Las claves son los nombres internos que usa el sistema y los valores son los textos visibles.
     */
    private $headers = [
        'codigo_de_pedido' => 'Código de Pedido',
        'estado' => 'Estado',
        'fecha_de_orden' => 'Fecha de Orden',
        'fecha_de_despacho' => 'Fecha de Despacho',
        'usuario' => 'Usuario',
        'cliente' => 'Cliente',
        'empresa' => 'Empresa',
        'nombre_fantasia' => 'Nombre Fantasía',
        'codigo_de_producto' => 'Código de Producto',
        'nombre_producto' => 'Nombre Producto',
        'categoria_producto' => 'Categoría',
        'cantidad' => 'Cantidad',
        'precio_neto' => 'Precio Neto',
        'precio_con_impuesto' => 'Precio con Impuesto',
        'precio_total_neto' => 'Precio Total Neto',
        'precio_total_con_impuesto' => 'Precio Total con Impuesto',
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

    public function map($orderLine): array
    {
        try {
            $order = $orderLine->order;
            $user = $order->user ?? null;
            $company = $user->company ?? null;
            $product = $orderLine->product ?? null;
            $category = $product->category ?? null;

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

            // Añadir comilla al inicio del código de pedido y código de producto
            // para evitar que Excel los convierta a notación científica
            $codigoPedido = ($order && $order->order_number) ? "'" . $order->order_number : null;
            $codigoProducto = ($product && $product->code) ? "'" . $product->code : null;
            
            // Calcular precios totales
            $precioTotalNeto = $orderLine->quantity * $orderLine->unit_price;
            $precioTotalConImpuesto = $orderLine->quantity * $orderLine->unit_price_with_tax;

            // Mapeamos los datos usando las mismas claves que espera el importador
            return [
                'codigo_de_pedido' => $codigoPedido,
                'estado' => $estadoOrden,
                'fecha_de_orden' => $fechaOrden,
                'fecha_de_despacho' => $fechaDespacho,
                'usuario' => $user ? $user->email : null,
                'cliente' => $user ? $user->name : null,
                'empresa' => $company ? $company->name : null,
                'nombre_fantasia' => $company ? $company->fantasy_name : null,
                'codigo_de_producto' => $codigoProducto,
                'nombre_producto' => $product ? $product->name : null,
                'categoria_producto' => $category ? $category->name : null,
                'cantidad' => $orderLine->quantity,
                'precio_neto' => '$' . number_format($orderLine->unit_price / 100, 2, '.', ','),
                'precio_con_impuesto' => '$' . number_format($orderLine->unit_price_with_tax / 100, 2, '.', ','),
                'precio_total_neto' => '$' . number_format($precioTotalNeto / 100, 2, '.', ','),
                'precio_total_con_impuesto' => '$' . number_format($precioTotalConImpuesto / 100, 2, '.', ','),
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