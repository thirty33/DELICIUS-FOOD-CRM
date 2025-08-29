<?php

namespace App\Exports;

use App\Models\CategoryMenu;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
use App\Models\ExportProcess;

class CategoryMenuDataExport implements 
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
        'titulo_del_menu' => 'Título del Menú',
        'nombre_de_categoria' => 'Nombre de Categoría',
        'mostrar_todos_los_productos' => 'Mostrar Todos los Productos',
        'orden_de_visualizacion' => 'Orden de Visualización',
        'categoria_obligatoria' => 'Categoría Obligatoria',
        'activo' => 'Activo',
        'productos' => 'Productos'
    ];

    private $exportProcessId;
    private $categoryMenuIds;

    public function __construct(Collection $categoryMenuIds, int $exportProcessId)
    {
        $this->categoryMenuIds = $categoryMenuIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return CategoryMenu::with(['menu', 'category', 'products'])
            ->whereIn('id', $this->categoryMenuIds);
    }
    
    public function chunkSize(): int
    {
        return 10;
    }

    public function map($categoryMenu): array
    {
        try {
            // Prepare products list if not showing all products
            $productList = $categoryMenu->show_all_products 
                ? '' 
                : $categoryMenu->products->pluck('code')->implode(',');

            return [
                'titulo_del_menu' => $categoryMenu->menu->title,
                'nombre_de_categoria' => $categoryMenu->category->name,
                'mostrar_todos_los_productos' => $categoryMenu->show_all_products ? '1' : '0',
                'orden_de_visualizacion' => $categoryMenu->display_order,
                'categoria_obligatoria' => $categoryMenu->mandatory_category ? '1' : '0',
                'activo' => $categoryMenu->is_active ? '1' : '0',
                'productos' => $productList
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando relación menú-categoría para exportación', [
                'export_process_id' => $this->exportProcessId,
                'category_menu_id' => $categoryMenu->id,
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
}