<?php

namespace App\Imports\Concerns;

/**
 * Single source of truth for user import/export column definitions.
 *
 * Used by:
 * - UserImport (heading map for import parsing)
 * - UserDataExport (headers + mapping order)
 * - UserTemplateExport (headers for empty template)
 * - UserImportTest / UserExportTest (header validation, cell-by-key access)
 *
 * To add/remove/reorder a column, change ONLY this class.
 */
class UserColumnDefinition
{
    /**
     * Ordered map: internal_key => Excel header label.
     *
     * The array order defines the column order in both imports and exports.
     */
    public const COLUMNS = [
        'nombre' => 'Nombre',
        'correo_electronico' => 'Correo Electrónico',
        'tipo_de_usuario' => 'Tipo de Usuario',
        'tipo_de_convenio' => 'Tipo de Convenio',
        'codigo_empresa' => 'Código Empresa',
        'empresa' => 'Empresa',
        'codigo_sucursal' => 'Código Sucursal',
        'nombre_fantasia_sucursal' => 'Nombre Fantasía Sucursal',
        'lista_de_precio' => 'Lista de Precio',
        'validar_fecha_y_reglas_de_despacho' => 'Validar Fecha y Reglas de Despacho',
        'validar_precio_minimo' => 'Validar Precio Mínimo',
        'validar_reglas_de_subcategoria' => 'Validar Reglas de Subcategoría',
        'usuario_maestro' => 'Usuario Maestro',
        'pedidos_en_fines_de_semana' => 'Pedidos en Fines de Semana',
        'nombre_de_usuario' => 'Nombre de Usuario',
        'contrasena' => 'Contraseña',
        'codigo_de_facturacion' => 'Codigo de Facturacion',
    ];

    /**
     * Import heading map: slug_key => model_field.
     *
     * Keys MUST match COLUMNS keys (Str::slug of the Excel header).
     */
    public const HEADING_MAP = [
        'nombre' => 'name',
        'correo_electronico' => 'email',
        'tipo_de_usuario' => 'roles',
        'tipo_de_convenio' => 'permissions',
        'codigo_empresa' => 'company_code',
        'empresa' => 'company_name',
        'codigo_sucursal' => 'branch_code',
        'nombre_fantasia_sucursal' => 'branch_fantasy_name',
        'lista_de_precio' => 'price_list_name',
        'validar_fecha_y_reglas_de_despacho' => 'allow_late_orders',
        'validar_precio_minimo' => 'validate_min_price',
        'validar_reglas_de_subcategoria' => 'validate_subcategory_rules',
        'usuario_maestro' => 'master_user',
        'pedidos_en_fines_de_semana' => 'allow_weekend_orders',
        'nombre_de_usuario' => 'nickname',
        'contrasena' => 'plain_password',
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
     * Get the Excel column letter for a given key (e.g. 'nombre' => 'A').
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
     * Get the cell reference for a given key and row (e.g. 'nombre', 2 => 'A2').
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