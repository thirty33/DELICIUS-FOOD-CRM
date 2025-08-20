<?php

namespace App\Exports;

use App\Models\Category;
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
use Throwable;
use Maatwebsite\Excel\Concerns\FromQuery;

class CategoryExport implements
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
        'nombre' => 'Nombre',
        'descripcion' => 'Descripción',
        'activo' => 'Activo',
        'subcategorias' => 'Subcategorías',
        'palabras_clave' => 'Palabras clave'
    ];

    private $exportProcessId;
    private $categoryIds;

    public function __construct(Collection $categories, int $exportProcessId)
    {
        $this->categoryIds = $categories->pluck('id')->toArray();
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return Category::whereIn('id', $this->categoryIds)
            ->with(['subcategories', 'categoryGroups']);
    }

    public function chunkSize(): int
    {
        return 1;
    }

    public function map($category): array
    {
        try {
            return [
                'nombre' => $category->name,
                'descripcion' => $category->description,
                'activo' => $category->is_active ? '1' : '0',
                'subcategorias' => $category->subcategories->pluck('name')->implode(', '),
                'palabras_clave' => $category->categoryGroups->pluck('name')->implode(', ')
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando categoría para exportación', [
                'export_process_id' => $this->exportProcessId,
                'category_id' => $category->id,
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

    /**
     * Handle a failed export.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $e): void
    {
        $error = [
            'row' => 0, // Error general
            'attribute' => 'general',
            'errors' => [$e->getMessage()],
            'values' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ];

        $exportProcess = ExportProcess::find($this->exportProcessId);

        if ($exportProcess) {
            $existingErrors = $exportProcess->error_log ?? [];
            $existingErrors[] = $error;

            $exportProcess->update([
                'error_log' => $existingErrors,
                'status' => ExportProcess::STATUS_PROCESSED_WITH_ERRORS
            ]);
        }

        Log::error('Error en exportación de categorías', [
            'export_process_id' => $this->exportProcessId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
