<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use App\Enums\Subcategory;

/**
 * Validates subcategory exclusion rules to ensure menu balance.
 * 
 * This validation enforces specific combination rules between subcategories:
 * - PLATO DE FONDO: Cannot have multiple main dishes
 * - ENTRADA: Cannot have multiple appetizers  
 * - FRIA vs HIPOCALORICO: Cold items cannot be combined with low-calorie options
 * - PAN DE ACOMPAÑAMIENTO vs SANDWICH: Bread accompaniment cannot be combined with sandwiches
 * 
 * Only applies when validate_subcategory_rules is TRUE and for individual agreement users.
 * Generates user-friendly messages explaining why certain combinations aren't allowed.
 */
class OneProductBySubcategoryValidation extends OrderStatusValidation
{
    /**
     * Friendly names for subcategories to make error messages more user-friendly
     */
    private const FRIENDLY_SUBCATEGORY_NAMES = [
        'PLATO DE FONDO' => 'plato principal',
        'ENTRADA' => 'entrada',
        'SANDWICH' => 'sandwich',
        'PAN DE ACOMPANAMIENTO' => 'pan de acompañamiento',
        'FRIA' => 'comida fría',
        'HIPOCALORICO' => 'opción ligera'
    ];

    protected $subcategoryExclusions = [
        Subcategory::PLATO_DE_FONDO->value => [Subcategory::PLATO_DE_FONDO->value],
        Subcategory::ENTRADA->value => [Subcategory::ENTRADA->value],
        Subcategory::FRIA->value => [Subcategory::HIPOCALORICO->value],
        Subcategory::PAN_DE_ACOMPANAMIENTO->value => [Subcategory::SANDWICH->value],
    ];

    protected function check(Order $order, User $user, Carbon $date): void
    {   
        if(!$user->validate_subcategory_rules) {
            return;
        }

        if (UserPermissions::IsAgreementIndividual($user)) {
            $filteredOrderLines = $order->orderLines->filter(function ($orderLine) {
                return $orderLine->product->category->subcategories->isNotEmpty();
            });

            if ($filteredOrderLines->isEmpty()) {
                return;
            }

            $productsWithSubcategories = $filteredOrderLines->map(function ($orderLine) {
                return [
                    'product_id' => $orderLine->product->id,
                    'category' => $orderLine->product->category,
                    'subcategories' => $orderLine->product->category->subcategories->pluck('name')->toArray(),
                    'product_name' => $orderLine->product->name,
                    'is_null_product' => $orderLine->product->is_null_product,
                ];
            });

            $this->validateSubcategoryExclusions($productsWithSubcategories);
        }
    }

    /**
     * Validate subcategory exclusions between products.
     *
     * @param \Illuminate\Support\Collection $products
     * @throws Exception
     */
    protected function validateSubcategoryExclusions($products): void
    {
        foreach ($products as $index => $product) {
            foreach ($this->subcategoryExclusions as $subcategory => $excludedSubcategories) {
                if (in_array($subcategory, $product['subcategories'])) {
                    foreach ($products as $otherIndex => $otherProduct) {
                        if ($otherIndex === $index) {
                            continue;
                        }

                        // Skip validation if either product is null product (e.g., "SIN PLATO DE FONDO")
                        // because null products represent absence of that type, not a real product
                        if ($product['is_null_product'] || $otherProduct['is_null_product']) {
                            continue;
                        }

                        foreach ($excludedSubcategories as $excludedSubcategory) {
                            if (in_array($excludedSubcategory, $otherProduct['subcategories'])) {
                                throw new Exception(
                                    $this->generateSubcategoryConflictMessage($subcategory, $excludedSubcategory)
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Get friendly name for a subcategory
     */
    private function getFriendlySubcategoryName(string $subcategory): string
    {
        return self::FRIENDLY_SUBCATEGORY_NAMES[$subcategory] ?? strtolower($subcategory);
    }

    /**
     * Generate user-friendly message for subcategory conflicts
     */
    private function generateSubcategoryConflictMessage(string $subcategory1, string $subcategory2): string
    {
        $friendly1 = $this->getFriendlySubcategoryName($subcategory1);
        $friendly2 = $this->getFriendlySubcategoryName($subcategory2);
        
        return "🍽️ Para mantener el balance de tu menú, no puedes combinar {$subcategory1} con {$subcategory2}.\n\n" .
               "💡 Nuestros chefs recomiendan elegir solo uno de estos tipos para una mejor experiencia gastronómica.";
    }
}