<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class CategoryMenuTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    private $headers = [
        'titulo_del_menu' => 'Título del Menú',
        'nombre_de_categoria' => 'Nombre de Categoría',
        'mostrar_todos_los_productos' => 'Mostrar Todos los Productos',
        'orden_de_visualizacion' => 'Orden de Visualización',
        'categoria_obligatoria' => 'Categoría Obligatoria',
        'productos' => 'Productos'
    ];

    /**
     * Retorna un array con los encabezados para el archivo de plantilla.
     *
     * @return array
     */
    public function array(): array
    {
        return [array_values($this->headers)];
    }

    /**
     * Aplica estilos a la hoja de cálculo.
     *
     * @param Worksheet $sheet
     * @return array
     */
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