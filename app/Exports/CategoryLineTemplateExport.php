<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CategoryLineTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    private $headers = [
        'categoria' => 'Categoría',
        'dia_de_semana' => 'Día de semana',
        'dias_de_preparacion' => 'Días de preparación',
        'hora_maxima_de_pedido' => 'Hora máxima de pedido',
        'activo' => 'Activo'
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
