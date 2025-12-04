<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Plated Dish Ingredients Template Export
 *
 * Exports a template Excel file with only headers for importing plated dish ingredients.
 * Headers match EXACTLY what PlatedDishIngredientsImport expects (7 columns).
 *
 * Template structure:
 * - 7 columns total
 * - Only header row (no data rows)
 * - Green header background (#E2EFDA)
 * - Bold header text
 * - Auto-sized columns
 *
 * VERTICAL FORMAT:
 * This template expects one row per ingredient:
 * - Same product can have multiple rows (one per ingredient)
 * - Product with 6 ingredients = 6 rows in the filled template
 *
 * Usage:
 * This template is downloaded by users, filled with data, and then imported
 * using the PlatedDishIngredientsImport class.
 */
class PlatedDishIngredientsTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    /**
     * Headers matching PlatedDishIngredientsImport expected headers
     * IMPORTANT: These MUST match the import headings exactly (7 columns total)
     *
     * From PlatedDishIngredientsImport::getExpectedHeaders():
     * 1. CODIGO DE PRODUCTO
     * 2. NOMBRE DE PRODUCTO
     * 3. EMPLATADO (ingredient code)
     * 4. UNIDAD DE MEDIDA
     * 5. CANTIDAD
     * 6. CANTIDAD MAXIMA (HORECA)
     * 7. VIDA UTIL
     */
    private array $headers = [
        'CODIGO DE PRODUCTO',
        'NOMBRE DE PRODUCTO',
        'EMPLATADO',
        'UNIDAD DE MEDIDA',
        'CANTIDAD',
        'CANTIDAD MAXIMA (HORECA)',
        'VIDA UTIL',
    ];

    /**
     * Return array with only headers (no data rows)
     *
     * @return array
     */
    public function array(): array
    {
        return [$this->headers];
    }

    /**
     * Apply styles to the header row
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
                    'startColor' => ['rgb' => 'E2EFDA'], // Light green background
                ],
            ],
        ];
    }
}