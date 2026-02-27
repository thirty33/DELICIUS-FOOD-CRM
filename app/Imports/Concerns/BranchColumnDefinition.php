<?php

namespace App\Imports\Concerns;

/**
 * Single source of truth for branch import/export column definitions.
 *
 * Used by:
 * - CompanyBranchesImport (heading map for import parsing)
 * - CompanyBranchesDataExport (headers + mapping order)
 * - CompanyBranchesExport (headers for empty template)
 * - BranchImportTest / BranchDataExportTest / BranchTemplateExportTest (header validation, cell-by-key access)
 *
 * To add/remove/reorder a column, change ONLY this class.
 */
class BranchColumnDefinition
{
    /**
     * Ordered map: internal_key => Excel header label.
     *
     * The array order defines the column order in both imports and exports.
     */
    public const COLUMNS = [
        'numero_de_registro_de_compania' => 'Número de Registro de Compañía',
        'codigo' => 'Código',
        'nombre_de_fantasia' => 'Nombre de Fantasía',
        'direccion' => 'Dirección',
        'direccion_de_despacho' => 'Dirección de Despacho',
        'nombre_de_contacto' => 'Nombre de Contacto',
        'apellido_de_contacto' => 'Apellido de Contacto',
        'telefono_de_contacto' => 'Teléfono de Contacto',
        'precio_pedido_minimo' => 'Precio Pedido Mínimo',
        'regla_de_transporte' => 'Regla de Transporte',
    ];

    /**
     * Get ordered header labels for Excel (used by exports and tests).
     */
    public static function headers(): array
    {
        return array_values(self::COLUMNS);
    }

    /**
     * Get the Excel column letter for a given key (e.g. 'codigo' => 'B').
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
     * Get the cell reference for a given key and row (e.g. 'codigo', 2 => 'B2').
     */
    public static function cell(string $key, int $row): string
    {
        return self::columnLetter($key).$row;
    }

    /**
     * Total number of columns.
     */
    public static function count(): int
    {
        return count(self::COLUMNS);
    }
}
