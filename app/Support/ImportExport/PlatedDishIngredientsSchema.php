<?php

namespace App\Support\ImportExport;

/**
 * Centralized Schema Definition for PlatedDishIngredients Import/Export
 *
 * This class serves as the SINGLE SOURCE OF TRUTH for all PlatedDishIngredients
 * import/export operations. Any changes to headers must be made ONLY here.
 *
 * Pattern follows Products Import/Export structure:
 * - Associative array with slug => Display Name
 * - Used by Import, DataExport, and TemplateExport classes
 * - Used by all test files
 *
 * Usage:
 * - Import classes: Use getHeadingMap() for internal field mapping
 * - Export classes: Use getHeaderValues() for Excel column headers
 * - Tests: Use getHeaderValues() for assertions
 *
 * When adding a new field:
 * 1. Add entry to getHeaders() array
 * 2. Add mapping to getHeadingMap() array
 * 3. All import/export classes and tests update automatically
 */
class PlatedDishIngredientsSchema
{
    /**
     * Master headers definition (associative array)
     *
     * This is the SINGLE SOURCE OF TRUTH for all headers.
     * Format: ['excel_slug' => 'Display Name']
     *
     * Excel slugs are snake_case versions that Laravel Excel creates automatically:
     * - "CODIGO DE PRODUCTO" → "codigo_de_producto"
     * - "NOMBRE DE PRODUCTO" → "nombre_de_producto"
     * - "EMPLATADO" → "emplatado"
     *
     * @return array Associative array of headers
     */
    public static function getHeaders(): array
    {
        return [
            'codigo_de_producto' => 'CODIGO DE PRODUCTO',
            'nombre_de_producto' => 'NOMBRE DE PRODUCTO',
            'emplatado' => 'EMPLATADO',
            'unidad_de_medida' => 'UNIDAD DE MEDIDA',
            'cantidad' => 'CANTIDAD',
            'cantidad_maxima_horeca' => 'CANTIDAD MAXIMA (HORECA)',
            'vida_util' => 'VIDA UTIL',
            'es_horeca' => 'ES HORECA',
            'producto_relacionado' => 'PRODUCTO RELACIONADO',
        ];
    }

    /**
     * Get header values only (for Export classes)
     *
     * Returns only the display names as indexed array.
     * Used by DataExport and TemplateExport for Excel column headers.
     *
     * @return array Indexed array of header display names
     */
    public static function getHeaderValues(): array
    {
        return array_values(self::getHeaders());
    }

    /**
     * Get heading map for Import class
     *
     * Maps Excel column slugs to internal model field names.
     * Used by PlatedDishIngredientsImport for data processing.
     *
     * @return array Associative array mapping Excel slugs to internal fields
     */
    public static function getHeadingMap(): array
    {
        return [
            'codigo_de_producto' => 'product_code',
            'nombre_de_producto' => 'product_name',
            'emplatado' => 'ingredient_code',
            'unidad_de_medida' => 'measure_unit',
            'cantidad' => 'quantity',
            'cantidad_maxima_horeca' => 'max_quantity_horeca',
            'vida_util' => 'shelf_life',
            'producto_relacionado' => 'related_product_code',
        ];
    }

    /**
     * Get header count
     *
     * @return int Number of headers
     */
    public static function getHeaderCount(): int
    {
        return count(self::getHeaders());
    }

    /**
     * Get Excel column letter for a specific header slug
     *
     * @param string $headerSlug Header slug (e.g., 'codigo_de_producto')
     * @return string|null Column letter (A-Z) or null if not found
     */
    public static function getColumnLetter(string $headerSlug): ?string
    {
        $headers = array_keys(self::getHeaders());
        $index = array_search($headerSlug, $headers);

        if ($index === false) {
            return null;
        }

        // Convert 0-based index to Excel column letter (0=A, 1=B, etc.)
        return chr(65 + $index);
    }

    /**
     * Get column index (0-based) for a specific header slug
     *
     * @param string $headerSlug Header slug (e.g., 'codigo_de_producto')
     * @return int|null 0-based column index or null if not found
     */
    public static function getColumnIndex(string $headerSlug): ?int
    {
        $headers = array_keys(self::getHeaders());
        $index = array_search($headerSlug, $headers);

        return $index !== false ? $index : null;
    }
}