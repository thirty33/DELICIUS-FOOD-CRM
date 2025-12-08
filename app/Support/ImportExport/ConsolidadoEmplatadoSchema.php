<?php

namespace App\Support\ImportExport;

/**
 * Schema for Consolidado Emplatado Report Export
 *
 * This schema defines the structure for the consolidated plated dishes report Excel.
 *
 * STRUCTURE:
 * - Fixed columns: PLATO, INGREDIENTE, CANTIDAD X PAX, INDIVIDUAL
 * - Dynamic client columns: One per branch (e.g., OTERO HORECA, ALIACE HORECA, etc.)
 * - Fixed summary columns: TOTAL HORECA, TOTAL BOLSAS
 *
 * EXAMPLE:
 * | PLATO | INGREDIENTE | CANTIDAD X PAX | INDIVIDUAL | OTERO HORECA | ALIACE HORECA | ... | TOTAL HORECA | TOTAL BOLSAS |
 *
 * USAGE:
 * 1. Get unique branches from data
 * 2. Call setClientColumns() with branch names
 * 3. Use getHeaders() to get complete header array
 * 4. Use getHeaderKeys() to map data to columns
 */
class ConsolidadoEmplatadoSchema
{
    /**
     * Fixed columns that appear before client columns
     */
    private const FIXED_PREFIX_COLUMNS = [
        'plato' => 'PLATO',
        'ingrediente' => 'INGREDIENTE',
        'cantidad_x_pax' => 'CANTIDAD X PAX',
        'individual' => 'INDIVIDUAL',
    ];

    /**
     * Fixed columns that appear after client columns
     */
    private const FIXED_SUFFIX_COLUMNS = [
        'total_horeca' => 'TOTAL HORECA',
        'total_bolsas' => 'TOTAL BOLSAS',
    ];

    /**
     * Dynamic client columns (branch names)
     * Format: ['branch_fantasy_name' => 'COLUMN HEADER']
     *
     * @var array
     */
    private static array $clientColumns = [];

    /**
     * Set dynamic client columns based on branch names
     *
     * This method should be called before getHeaders() or getHeaderKeys()
     * to configure which client columns should appear in the report.
     *
     * @param array $branchNames Array of branch fantasy names (e.g., ['OTERO HORECA', 'ALIACE HORECA'])
     * @return void
     *
     * @example
     * ConsolidadoEmplatadoSchema::setClientColumns(['OTERO HORECA', 'ALIACE HORECA']);
     */
    public static function setClientColumns(array $branchNames): void
    {
        self::$clientColumns = [];

        foreach ($branchNames as $branchName) {
            // Use normalized key for array indexing
            $key = self::normalizeClientKey($branchName);
            self::$clientColumns[$key] = $branchName;
        }
    }

    /**
     * Get all headers including dynamic client columns
     *
     * Returns array with structure: ['key' => 'HEADER DISPLAY NAME']
     *
     * @return array
     *
     * @example
     * [
     *     'plato' => 'PLATO',
     *     'ingrediente' => 'INGREDIENTE',
     *     'cantidad_x_pax' => 'CANTIDAD X PAX',
     *     'individual' => 'INDIVIDUAL',
     *     'client_otero_horeca' => 'OTERO HORECA',
     *     'client_aliace_horeca' => 'ALIACE HORECA',
     *     'total_horeca' => 'TOTAL HORECA',
     *     'total_bolsas' => 'TOTAL BOLSAS',
     * ]
     */
    public static function getHeaders(): array
    {
        return array_merge(
            self::FIXED_PREFIX_COLUMNS,
            self::$clientColumns,
            self::FIXED_SUFFIX_COLUMNS
        );
    }

    /**
     * Get header values only (for Excel column headers)
     *
     * Returns only display names as indexed array.
     *
     * @return array
     *
     * @example
     * ['PLATO', 'INGREDIENTE', 'CANTIDAD X PAX', 'INDIVIDUAL', 'OTERO HORECA', 'ALIACE HORECA', 'TOTAL HORECA', 'TOTAL BOLSAS']
     */
    public static function getHeaderValues(): array
    {
        return array_values(self::getHeaders());
    }

    /**
     * Get header keys only (for data mapping)
     *
     * Returns only keys as indexed array.
     *
     * @return array
     *
     * @example
     * ['plato', 'ingrediente', 'cantidad_x_pax', 'individual', 'client_otero_horeca', 'client_aliace_horeca', 'total_horeca', 'total_bolsas']
     */
    public static function getHeaderKeys(): array
    {
        return array_keys(self::getHeaders());
    }

    /**
     * Get total number of columns including dynamic client columns
     *
     * @return int
     */
    public static function getHeaderCount(): int
    {
        return count(self::getHeaders());
    }

    /**
     * Get client column key for a given branch name
     *
     * This is used to map branch data to the correct column key.
     *
     * @param string $branchName Branch fantasy name (e.g., 'OTERO HORECA')
     * @return string Column key (e.g., 'client_otero_horeca')
     *
     * @example
     * getClientColumnKey('OTERO HORECA') => 'client_otero_horeca'
     */
    public static function getClientColumnKey(string $branchName): string
    {
        return self::normalizeClientKey($branchName);
    }

    /**
     * Check if a branch has a configured column
     *
     * @param string $branchName Branch fantasy name
     * @return bool
     */
    public static function hasClientColumn(string $branchName): bool
    {
        $key = self::normalizeClientKey($branchName);
        return isset(self::$clientColumns[$key]);
    }

    /**
     * Get all configured client column keys
     *
     * @return array Array of client column keys
     *
     * @example
     * ['client_otero_horeca', 'client_aliace_horeca', 'client_unicon_lo_espejo']
     */
    public static function getClientColumnKeys(): array
    {
        return array_keys(self::$clientColumns);
    }

    /**
     * Get client columns mapping (key => display name)
     *
     * @return array
     *
     * @example
     * [
     *     'client_otero_horeca' => 'OTERO HORECA',
     *     'client_aliace_horeca' => 'ALIACE HORECA',
     * ]
     */
    public static function getClientColumns(): array
    {
        return self::$clientColumns;
    }

    /**
     * Normalize branch name to valid column key
     *
     * Converts branch fantasy name to a valid array key.
     *
     * @param string $branchName Branch fantasy name
     * @return string Normalized key
     *
     * @example
     * normalizeClientKey('OTERO HORECA') => 'client_otero_horeca'
     * normalizeClientKey('UNICON LO ESPEJO') => 'client_unicon_lo_espejo'
     */
    private static function normalizeClientKey(string $branchName): string
    {
        $normalized = strtolower($branchName);
        $normalized = str_replace(' ', '_', $normalized);
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized);

        return 'client_' . $normalized;
    }

    /**
     * Reset client columns (useful for testing)
     *
     * @return void
     */
    public static function resetClientColumns(): void
    {
        self::$clientColumns = [];
    }

    /**
     * Get fixed prefix columns (columns before client columns)
     *
     * @return array
     */
    public static function getFixedPrefixColumns(): array
    {
        return self::FIXED_PREFIX_COLUMNS;
    }

    /**
     * Get fixed suffix columns (columns after client columns)
     *
     * @return array
     */
    public static function getFixedSuffixColumns(): array
    {
        return self::FIXED_SUFFIX_COLUMNS;
    }
}