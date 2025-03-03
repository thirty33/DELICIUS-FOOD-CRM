<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CompanyBranchesExport implements FromArray, WithStyles, ShouldAutoSize
{
    private $headers = [
        'numero_de_registro_de_compania' => 'Número de Registro de Compañía',
        'codigo' => 'Código',
        'nombre_de_fantasia' => 'Nombre de Fantasía',
        'direccion' => 'Dirección',
        'direccion_de_despacho' => 'Dirección de Despacho',
        'nombre_de_contacto' => 'Nombre de Contacto',
        'apellido_de_contacto' => 'Apellido de Contacto',
        'telefono_de_contacto' => 'Teléfono de Contacto',
        'precio_pedido_minimo' => 'Precio Pedido Mínimo'
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