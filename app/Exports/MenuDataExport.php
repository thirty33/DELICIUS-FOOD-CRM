<?php

namespace App\Exports;

use App\Models\Menu;
use App\Models\ExportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Carbon\Carbon;
use Throwable;

class MenuDataExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    WithColumnFormatting,
    ShouldQueue,
    WithChunkReading
{
    use Exportable;

    private $headers = [
        'titulo' => 'Título',
        'descripcion' => 'Descripción',
        'fecha_de_despacho' => 'Fecha de Despacho',
        'tipo_de_usuario' => 'Tipo de Usuario',
        'tipo_de_convenio' => 'Tipo de Convenio',
        'fecha_hora_maxima_pedido' => 'Fecha Hora Máxima Pedido',
        'activo' => 'Activo'
    ];

    private $exportProcessId;
    private $menuIds;

    public function __construct(Collection $menuIds, int $exportProcessId)
    {
        $this->menuIds = $menuIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return Menu::with(['rol', 'permission'])
            ->whereIn('id', $this->menuIds);
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function map($menu): array
    {
        try {
            return [
                'titulo' => $menu->title,
                'descripcion' => $menu->description,
                'fecha_de_despacho' => $menu->publication_date ? "'" . Carbon::parse($menu->publication_date)->format('d/m/Y') : null,
                'tipo_de_usuario' => $menu->rol ? $menu->rol->name : null,
                'tipo_de_convenio' => $menu->permission ? $menu->permission->name : null,
                'fecha_hora_maxima_pedido' => $menu->max_order_date ? "'" . Carbon::parse($menu->max_order_date)->format('d/m/Y H:i:s') : null, // Añadimos comilla al inicio
                'activo' => $menu->active ? '1' : '0',
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando menú para exportación', [
                'export_process_id' => $this->exportProcessId,
                'menu_id' => $menu->id,
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
            'F' => [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT,
                ]
            ],
        ];
    }

    public function columnFormats(): array
    {
        return [
            'C' => NumberFormat::FORMAT_TEXT, // Cambiar a formato texto para fecha_de_despacho
            'F' => NumberFormat::FORMAT_TEXT, // Para fecha_hora_maxima_pedido
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

        Log::error('Error en exportación de menús', [
            'export_process_id' => $this->exportProcessId,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
