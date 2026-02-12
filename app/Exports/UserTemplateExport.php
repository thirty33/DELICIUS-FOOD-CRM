<?php

namespace App\Exports;

use App\Imports\Concerns\UserColumnDefinition;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class UserTemplateExport implements FromArray, WithStyles, ShouldAutoSize, WithHeadings
{
    private $headers = UserColumnDefinition::COLUMNS;

    public function array(): array
    {
        // Retorna solo la fila de encabezados en la plantilla
        return [];
    }
    
    public function headings(): array
    {
        return array_values($this->headers);
    }

    public function styles(Worksheet $sheet)
    {
        // Configurar anchos de columna específicos
        $sheet->getColumnDimension('A')->setWidth(50); // Nombre
        $sheet->getColumnDimension('B')->setWidth(40); // Correo electrónico

        // Las demás columnas con ancho estándar (A-Q)
        for ($i = 'C'; $i <= 'Q'; $i++) {
            $sheet->getColumnDimension($i)->setWidth(25);
        }

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