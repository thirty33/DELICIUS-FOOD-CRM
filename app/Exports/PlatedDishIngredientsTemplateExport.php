<?php

namespace App\Exports;

use App\Support\ImportExport\PlatedDishIngredientsSchema;
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
     * NOTE: Headers are now centralized in PlatedDishIngredientsSchema class.
     * Any changes to headers must be made in that class only.
     */

    /**
     * Return array with only headers (no data rows)
     *
     * @return array
     */
    public function array(): array
    {
        return [PlatedDishIngredientsSchema::getHeaderValues()];
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