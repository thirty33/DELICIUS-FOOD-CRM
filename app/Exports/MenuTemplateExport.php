<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class MenuTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    private $headers = [
        'titulo' => 'Título',
        'descripcion' => 'Descripción',
        'fecha_de_despacho' => 'Fecha de Despacho',
        'tipo_de_usuario' => 'Tipo de Usuario',
        'tipo_de_convenio' => 'Tipo de Convenio',
        'fecha_hora_maxima_pedido' => 'Fecha Hora Máxima Pedido',
        'activo' => 'Activo',
        'empresas_asociadas' => 'Empresas Asociadas'
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