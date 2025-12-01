<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

/**
 * Nutritional Information Template Export
 *
 * Exports a template Excel file with only headers for importing nutritional information.
 * Headers match EXACTLY what NutritionalInformationImport expects (28 columns).
 *
 * Template structure:
 * - 28 columns total (26 original + 2 warning text fields)
 * - Only header row (no data rows)
 * - Green header background (#E2EFDA)
 * - Bold header text
 * - Auto-sized columns
 *
 * Usage:
 * This template is downloaded by users, filled with data, and then imported
 * using the NutritionalInformationImport class.
 */
class NutritionalInformationTemplateExport implements FromArray, WithStyles, ShouldAutoSize
{
    /**
     * Headers matching NutritionalInformationImport expected headers
     * IMPORTANT: These MUST match the import headings exactly (28 columns total)
     *
     * Order matters! Columns 27-28 (warning text fields) must come AFTER
     * VIDA UTIL (25) and GENERAR ETIQUETA (26)
     */
    private $headers = [
        'codigo_de_producto' => 'CÓDIGO DE PRODUCTO',       // 1
        'nombre_de_producto' => 'NOMBRE DE PRODUCTO',       // 2
        'codigo_de_barras' => 'CODIGO DE BARRAS',           // 3
        'ingrediente' => 'INGREDIENTE',                     // 4
        'alergenos' => 'ALERGENOS',                         // 5
        'unidad_de_medida' => 'UNIDAD DE MEDIDA',           // 6
        'peso_neto' => 'PESO NETO',                         // 7
        'peso_bruto' => 'PESO BRUTO',                       // 8
        'calorias' => 'CALORIAS',                           // 9
        'proteina' => 'PROTEINA',                           // 10
        'grasa' => 'GRASA',                                 // 11
        'grasa_saturada' => 'GRASA SATURADA',               // 12
        'grasa_monoinsaturada' => 'GRASA MONOINSATURADA',   // 13
        'grasa_poliinsaturada' => 'GRASA POLIINSATURADA',   // 14
        'grasa_trans' => 'GRASA TRANS',                     // 15
        'colesterol' => 'COLESTEROL',                       // 16
        'carbohidrato' => 'CARBOHIDRATO',                   // 17
        'fibra' => 'FIBRA',                                 // 18
        'azucar' => 'AZUCAR',                               // 19
        'sodio' => 'SODIO',                                 // 20
        'alto_sodio' => 'ALTO SODIO',                       // 21
        'alto_calorias' => 'ALTO CALORIAS',                 // 22
        'alto_en_grasas' => 'ALTO EN GRASAS',               // 23
        'alto_en_azucares' => 'ALTO EN AZUCARES',           // 24
        'vida_util' => 'VIDA UTIL',                         // 25
        'generar_etiqueta' => 'GENERAR ETIQUETA',           // 26
        'mostrar_texto_soya' => 'MOSTRAR TEXTO SOYA',       // 27
        'mostrar_texto_pollo' => 'MOSTRAR TEXTO POLLO',     // 28
    ];

    /**
     * Return array with only headers (no data rows)
     *
     * @return array
     */
    public function array(): array
    {
        return [array_values($this->headers)];
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
                    'startColor' => ['rgb' => 'E2EFDA']
                ]
            ],
        ];
    }
}