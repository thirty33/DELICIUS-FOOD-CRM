<?php

namespace App\Contracts;

interface ConsolidadoEmplatadoRepositoryInterface
{
    /**
     * Get consolidated plated dish report data with bag calculations.
     *
     * @param array $advanceOrderIds Array of advance order IDs to include in the report
     * @param bool $flatFormat If true, returns flat format ready for Excel; if false, returns nested format
     * @return array Structured data with products, ingredients, columns, and bag calculations
     */
    public function getConsolidatedPlatedDishData(array $advanceOrderIds, bool $flatFormat = false): array;

    /**
     * Get unique column names from advance order IDs.
     *
     * @param array $advanceOrderIds Array of advance order IDs
     * @return array Array of unique column names, sorted alphabetically
     */
    public function getColumnNamesFromAdvanceOrders(array $advanceOrderIds): array;
}