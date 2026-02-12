<?php

namespace App\Imports\Concerns;

/**
 * Single source of truth for order line import/export column definitions.
 *
 * Used by:
 * - OrderLinesImport (heading map for import parsing)
 * - OrderLineExport (headers + mapping order)
 * - OrderLineExportTest (header validation, cell-by-key access)
 *
 * To add/remove/reorder a column, change ONLY this class.
 */
class OrderLineColumnDefinition
{
    /**
     * Ordered map: internal_key => Excel header label.
     *
     * The array order defines the column order in both imports and exports.
     * Keys are the Str::slug versions of the Excel header labels.
     */
    public const COLUMNS = [
        'id_orden' => 'ID Orden',
        'codigo_de_pedido' => 'Código de Pedido',
        'estado' => 'Estado',
        'fecha_de_orden' => 'Fecha de Orden',
        'fecha_de_despacho' => 'Fecha de Despacho',
        'codigo_de_empresa' => 'Código de Empresa',
        'empresa' => 'Empresa',
        'codigo_sucursal' => 'Código Sucursal',
        'nombre_fantasia_sucursal' => 'Nombre Fantasía Sucursal',
        'usuario' => 'Usuario',
        'codigo_de_facturacion_usuario' => 'Codigo de Facturacion Usuario',
        'categoria' => 'Categoría',
        'codigo_de_producto' => 'Código de Producto',
        'codigo_de_facturacion_producto' => 'Codigo de Facturacion Producto',
        'nombre_producto' => 'Nombre Producto',
        'cantidad' => 'Cantidad',
        'precio_neto' => 'Precio Neto',
        'precio_con_impuesto' => 'Precio con Impuesto',
        'precio_total_neto' => 'Precio Total Neto',
        'precio_total_con_impuesto' => 'Precio Total con Impuesto',
        'parcialmente_programado' => 'Parcialmente Programado',
    ];

    /**
     * Import heading map: slug_key => model_field.
     *
     * Keys MUST match COLUMNS keys (Str::slug of the Excel header).
     */
    public const HEADING_MAP = [
        'id_orden' => 'order_id',
        'codigo_de_pedido' => 'order_number',
        'estado' => 'status',
        'fecha_de_orden' => 'created_at',
        'fecha_de_despacho' => 'dispatch_date',
        'codigo_de_empresa' => 'company_code',
        'empresa' => 'company_name',
        'codigo_sucursal' => 'branch_code',
        'nombre_fantasia_sucursal' => 'branch_fantasy_name',
        'usuario' => 'user_email',
        'codigo_de_facturacion_usuario' => 'user_billing_code',
        'categoria' => 'category_name',
        'codigo_de_producto' => 'product_code',
        'codigo_de_facturacion_producto' => 'product_billing_code',
        'nombre_producto' => 'product_name',
        'cantidad' => 'quantity',
        'precio_neto' => 'unit_price',
        'precio_con_impuesto' => 'unit_price_with_tax',
        'precio_total_neto' => 'total_price_net',
        'precio_total_con_impuesto' => 'total_price_with_tax',
        'parcialmente_programado' => 'partially_scheduled',
    ];

    /**
     * Get ordered header labels for Excel (used by exports and tests).
     */
    public static function headers(): array
    {
        return array_values(self::COLUMNS);
    }

    /**
     * Get the Excel column letter for a given key (e.g. 'id_orden' => 'A').
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
     * Get the cell reference for a given key and row (e.g. 'id_orden', 2 => 'A2').
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