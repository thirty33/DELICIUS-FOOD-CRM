<?php

namespace App\Contracts;

interface ConsolidadoEmplatadoRepositoryInterface
{
    /**
     * Get consolidated plated dish report data with bag calculations.
     *
     * @param array $advanceOrderIds Array of advance order IDs to include in the report
     * @return array Structured data with products, ingredients, branches, and bag calculations
     */
    public function getConsolidatedPlatedDishData(array $advanceOrderIds): array;
}