<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PriceListTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    private $headers = [
        'nombre_de_lista_de_precio' => 'Nombre de Lista de Precio',
        'precio_minimo' => 'Precio Mínimo',
        'descripcion' => 'Descripción',
        'nombre_producto' => 'Nombre Producto',
        'codigo_de_producto' => 'Código de Producto',
        'precio_unitario' => 'Precio Unitario'
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