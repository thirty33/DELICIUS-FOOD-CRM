<?php

namespace App\Imports\Concerns;

/**
 * Single source of truth for product import/export column definitions.
 *
 * Used by:
 * - ProductsImport (heading map for import parsing)
 * - ProductsDataExport (headers + mapping order)
 * - ProductsTemplateExport (headers for empty template)
 * - ProductsDataExportTest (header validation, cell-by-key access)
 *
 * To add/remove/reorder a column, change ONLY this class.
 */
class ProductColumnDefinition
{
    /**
     * Ordered map: internal_key => Excel header label.
     *
     * The array order defines the column order in both imports and exports.
     */
    public const COLUMNS = [
        'codigo' => 'Código',
        'nombre' => 'Nombre',
        'descripcion' => 'Descripción',
        'precio' => 'Precio',
        'categoria' => 'Categoría',
        'unidad_de_medida' => 'Unidad de Medida',
        'nombre_archivo_original' => 'Nombre Archivo Original',
        'precio_lista' => 'Precio Lista',
        'stock' => 'Stock',
        'peso' => 'Peso',
        'permitir_ventas_sin_stock' => 'Permitir Ventas sin Stock',
        'activo' => 'Activo',
        'ingredientes' => 'Ingredientes',
        'areas_de_produccion' => 'Áreas de Producción',
        'codigo_de_facturacion' => 'Codigo de Facturacion',
    ];

    /**
     * Import heading map: slug_key => model_field.
     *
     * Keys MUST match COLUMNS keys (Str::slug of the Excel header).
     */
    public const HEADING_MAP = [
        'codigo' => 'code',
        'nombre' => 'name',
        'descripcion' => 'description',
        'precio' => 'price',
        'categoria' => 'category_id',
        'unidad_de_medida' => 'measure_unit',
        'nombre_archivo_original' => 'original_filename',
        'precio_lista' => 'price_list',
        'stock' => 'stock',
        'peso' => 'weight',
        'permitir_ventas_sin_stock' => 'allow_sales_without_stock',
        'activo' => 'active',
        'ingredientes' => '_ingredients',
        'areas_de_produccion' => '_production_areas',
        'codigo_de_facturacion' => 'billing_code',
    ];

    /**
     * Get ordered header labels for Excel (used by exports and tests).
     */
    public static function headers(): array
    {
        return array_values(self::COLUMNS);
    }

    /**
     * Get the Excel column letter for a given key (e.g. 'codigo' => 'A').
     */
    public static function columnLetter(string $key): string
    {
        $keys = array_keys(self::COLUMNS);
        $index = array_search($key, $keys, true);

        if ($index === false) {
            throw new \InvalidArgumentException("Unknown column key: {$key}");
        }

        return chr(ord('A') + $index);
    }

    /**
     * Get the cell reference for a given key and row (e.g. 'codigo', 2 => 'A2').
     */
    public static function cell(string $key, int $row): string
    {
        return self::columnLetter($key) . $row;
    }

    /**
     * Total number of columns.
     */
    public static function count(): int
    {
        return count(self::COLUMNS);
    }
}