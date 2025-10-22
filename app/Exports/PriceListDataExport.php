<?php

namespace App\Exports;

use App\Models\PriceList;
use App\Models\ExportProcess;
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

class PriceListDataExport implements
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
        'nombre_de_lista_de_precio' => 'Nombre de Lista de Precio',
        'categoria' => 'Categoría',
        'codigo_de_producto' => 'Código de Producto',
        'nombre_producto' => 'Nombre Producto',
        'descripcion' => 'Descripción',
        'precio_unitario' => 'Precio Unitario'
    ];

    private $exportProcessId;
    private $priceListIds;

    public function __construct(Collection $priceListIds, int $exportProcessId)
    {
        $this->priceListIds = $priceListIds;
        $this->exportProcessId = $exportProcessId;
    }

    public function query()
    {
        return PriceList::with(['companies', 'priceListLines.product.category'])
            ->whereIn('id', $this->priceListIds);
    }
    
    public function chunkSize(): int
    {
        return 1;
    }

    public function map($priceList): array
    {
        try {
            // Si no hay líneas de precio, devolver una sola fila con los datos de la lista
            if ($priceList->priceListLines->isEmpty()) {
                return [
                    'nombre_de_lista_de_precio' => $priceList->name,
                    'categoria' => null,
                    'codigo_de_producto' => null,
                    'nombre_producto' => null,
                    'descripcion' => $priceList->description,
                    'precio_unitario' => null,
                ];
            }

            // Si hay líneas de precio, crear una fila por cada línea
            $rows = [];
            foreach ($priceList->priceListLines as $line) {
                $rows[] = [
                    'nombre_de_lista_de_precio' => $priceList->name,
                    'categoria' => $line->product && $line->product->category ? $line->product->category->name : null,
                    'codigo_de_producto' => $line->product ? $line->product->code : null,
                    'nombre_producto' => $line->product ? $line->product->name : null,
                    'descripcion' => $priceList->description,
                    'precio_unitario' => $line->unit_price ? '$' . number_format($line->unit_price / 100, 2, '.', ',') : null,
                ];
            }

            return $rows;
        } catch (\Exception $e) {
            Log::error('Error mapeando lista de precios para exportación', [
                'export_process_id' => $this->exportProcessId,
                'price_list_id' => $priceList->id,
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