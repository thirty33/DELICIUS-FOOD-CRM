<?php

namespace App\Exports;

use App\Imports\Concerns\ProductColumnDefinition;
use App\Models\Product;
use App\Models\ExportProcess;
use App\Repositories\MasterCategoryRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
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
use Maatwebsite\Excel\Concerns\FromQuery;

class ProductsDataExport implements
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

    private $headers = ProductColumnDefinition::COLUMNS;

    private $exportProcessId;
    private $productIds;
    private MasterCategoryRepository $masterCategoryRepository;

    public function __construct(Collection $productIds, int $exportProcessId)
    {
        $this->productIds = $productIds;
        $this->exportProcessId = $exportProcessId;
        $this->masterCategoryRepository = app(MasterCategoryRepository::class);
    }

    public function query()
    {
        return Product::with(['category.masterCategories', 'ingredients', 'productionAreas'])
            ->whereIn('id', $this->productIds);
    }
    
    public function chunkSize(): int
    {
        return 1;
    }

    public function map($product): array
    {
        try {
            return [
                'codigo' => $product->code,
                'nombre' => $product->name,
                'descripcion' => $product->description,
                'precio' => '$' . number_format($product->price / 100, 2, '.', ','),
                'categoria_maestra' => $this->masterCategoryRepository->getNamesForCategory($product->category_id)->implode(', '),
                'categoria' => $product->category->name,
                'unidad_de_medida' => $product->measure_unit,
                'nombre_archivo_original' => $product->original_filename,
                'precio_lista' => $product->price_list ? '$' . number_format($product->price_list / 100, 2, '.', ',') : null,
                'stock' => $product->stock !== null ? "'" . $product->stock : null,
                'peso' => $product->weight !== null ? "'" . $product->weight : null,
                'permitir_ventas_sin_stock' => $product->allow_sales_without_stock ? 'VERDADERO' : 'FALSO',
                'activo' => $product->active ? 'VERDADERO' : 'FALSO',
                'ingredientes' => $product->ingredients->pluck('descriptive_text')->implode(', '),
                'areas_de_produccion' => $product->productionAreas->pluck('name')->implode(', '),
                'codigo_de_facturacion' => $product->billing_code ?? '',
            ];
        } catch (\Exception $e) {
            Log::error('Error mapeando producto para exportación', [
                'export_process_id' => $this->exportProcessId,
                'product_id' => $product->id,
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
            ProductColumnDefinition::columnLetter('stock') => NumberFormat::FORMAT_TEXT,
            ProductColumnDefinition::columnLetter('peso') => NumberFormat::FORMAT_TEXT,
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
