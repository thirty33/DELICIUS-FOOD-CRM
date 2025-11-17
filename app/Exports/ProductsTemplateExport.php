<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ProductsTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    private $headers = [
        'codigo' => 'Código',
        'nombre' => 'Nombre',
        'descripcion' => 'Descripción',
        'precio' => 'Precio',
        'categoria' => 'Categoría',
        'unidad_de_medida' => 'Unidad de Medida',
        'nombre_archivo_original' => 'Nombre Archivo Original',
        'precio_lista' => 'Precio Lista',
        'stock' => 'Stock',
        'peso' => 'Peso',
        'permitir_ventas_sin_stock' => 'Permitir Ventas sin Stock',
        'activo' => 'Activo',
        'ingredientes' => 'Ingredientes',
        'areas_de_produccion' => 'Áreas de Producción'
    ];

    public function array(): array
    {
        return [array_values($this->headers)];
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
}