<?php

namespace App\Exports;

use App\Models\CategoryLine;
use App\Models\ExportProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
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

class CategoryLineDataExport implements
    FromCollection,
    WithHeadings,
    WithMapping,
    ShouldAutoSize,
    WithStyles,
    WithEvents,
    ShouldQueue,
    WithChunkReading
{
    use Exportable;

    private $daysMap = [
        'monday' => 'LUNES',
        'tuesday' => 'MARTES',
        'wednesday' => 'MIÉRCOLES',
        'thursday' => 'JUEVES',
        'friday' => 'VIERNES',
        'saturday' => 'SÁBADO',
        'sunday' => 'DOMINGO'
    ];

    private $headers = [
        'categoria' => 'Categoría',
        'dia_de_semana' => 'Día de semana',
        'dias_de_preparacion' => 'Días de preparación',
        'hora_maxima_de_pedido' => 'Hora máxima de pedido',
        'activo' => 'Activo'
    ];

    private $exportProcessId;
    private $categoryLineIds;

    public function __construct(Collection $categoryLineIds, int $exportProcessId)
    {
        $this->categoryLineIds = $categoryLineIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function collection()
    {
        return CategoryLine::with('category')
            ->whereIn('id', $this->categoryLineIds)
            ->get();
    }

    public function chunkSize(): int
    {
        return 1;
    }

    public function map($categoryLine): array
    {
        try {
            return [
                'categoria' => $categoryLine->category->name,
                'dia_de_semana' => $this->daysMap[$categoryLine->weekday],
                'dias_de_preparacion' => $categoryLine->preparation_days,
                'hora_maxima_de_pedido' => $categoryLine->maximum_order_time->format('H:i'),
                'activo' => $categoryLine->active ? 'VERDADERO' : 'FALSO'
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando regla de despacho para exportación', [
                'export_process_id' => $this->exportProcessId,
                'category_line_id' => $categoryLine->id,
                'error' => $e->getMessage()
            ]);

            // throw $e;
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
}
