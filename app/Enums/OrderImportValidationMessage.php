<?php

namespace App\Enums;

/**
 * Order Import Validation Messages Enum
 *
 * Centralizes all validation error messages for OrderLinesImport
 * Used by both the import class and test classes to ensure consistency
 */
enum OrderImportValidationMessage: string
{
    // Standard validation rules (rules() method)
    case ID_ORDEN_INTEGER = 'El ID de orden debe ser un número entero.';
    case ESTADO_REQUIRED = 'El estado del pedido es obligatorio.';
    case ESTADO_INVALID = 'El estado del pedido debe ser uno de los siguientes: Pendiente, Procesado, Cancelado, Parcialmente Agendado (o en mayúsculas: PENDIENTE, PROCESADO, CANCELADO, PARCIALMENTE AGENDADO).';
    case FECHA_ORDEN_REQUIRED = 'La fecha de orden es obligatoria.';
    case FECHA_DESPACHO_REQUIRED = 'La fecha de despacho es obligatoria.';
    case CODIGO_EMPRESA_REQUIRED = 'El código de empresa es obligatorio.';
    case CODIGO_EMPRESA_STRING = 'El código de empresa debe ser un texto.';
    case CODIGO_SUCURSAL_REQUIRED = 'El código de sucursal es obligatorio.';
    case CODIGO_SUCURSAL_STRING = 'El código de sucursal debe ser un texto.';
    case USUARIO_REQUIRED = 'El usuario es obligatorio.';
    case USUARIO_STRING = 'El usuario debe ser un texto (email o nickname).';
    case CODIGO_PRODUCTO_REQUIRED = 'El código de producto es obligatorio.';
    case CANTIDAD_REQUIRED = 'La cantidad es obligatoria.';
    case CANTIDAD_INTEGER = 'La cantidad debe ser un número entero.';
    case CANTIDAD_MIN = 'La cantidad debe ser al menos 1.';
    case PRECIO_NETO_NUMERIC = 'El precio neto debe ser un número.';
    case PARCIALMENTE_PROGRAMADO_IN = 'El campo parcialmente programado debe tener un valor válido (0, 1, true, false, si, no).';

    // Custom validation rules (withValidator method)
    case FECHA_ORDEN_FORMAT = 'El formato de la fecha de orden debe ser DD/MM/YYYY o un número de fecha de Excel.';
    case FECHA_DESPACHO_FORMAT = 'El formato de la fecha de despacho debe ser DD/MM/YYYY o un número de fecha de Excel.';
    case USUARIO_NOT_EXISTS = 'El usuario especificado no existe (buscado por email o nickname).';
    case PRODUCTO_NOT_EXISTS = 'El producto con código {code} no existe en el sistema.';

    /**
     * Get message with placeholder replacement
     *
     * @param array<string, string> $replacements Key-value pairs for placeholder replacement
     * @return string
     */
    public function message(array $replacements = []): string
    {
        $message = $this->value;

        foreach ($replacements as $key => $value) {
            $message = str_replace("{{$key}}", $value, $message);
        }

        return $message;
    }

    /**
     * Get regex pattern to match this message in tests
     *
     * @return string
     */
    public function pattern(): string
    {
        // Replace placeholders with regex patterns
        $pattern = preg_quote($this->value, '/');
        $pattern = str_replace('\\{code\\}', '.*', $pattern);

        return $pattern;
    }
}
