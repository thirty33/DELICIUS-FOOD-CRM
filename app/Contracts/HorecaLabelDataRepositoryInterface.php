<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

/**
 * Interface for HORECA label data repository
 *
 * Handles grouping logic for HORECA label generation based on orders
 */
interface HorecaLabelDataRepositoryInterface
{
    /**
     * Get label data grouped by branch and max_quantity_horeca
     *
     * Performs the following groupings:
     * 1. Groups by branch (order lines with same branch)
     * 2. Groups by ingredient (product -> plated dish -> ingredients)
     * 3. Calculates total needed quantity per ingredient per branch
     * 4. Splits by max_quantity_horeca if total exceeds max
     *
     * @param array $orderIds Array of order IDs
     * @return Collection Collection of label data items with structure:
     *   [
     *     'ingredient_name' => string,
     *     'ingredient_product_code' => string (product code from ingredient name if available),
     *     'branch_id' => int,
     *     'branch_fantasy_name' => string,
     *     'measure_unit' => string,
     *     'total_quantity_needed' => float (total quantity needed for this branch),
     *     'max_quantity_horeca' => float (max quantity per label),
     *     'labels_count' => int (number of labels needed),
     *     'weights' => array (individual weights for each label, e.g., [1000, 500]),
     *   ]
     */
    public function getHorecaLabelDataByOrders(array $orderIds): Collection;
}