<?php

namespace App\Classes\Orders\Validations;

use App\Models\Order;
use App\Classes\UserPermissions;
use App\Models\User;
use App\Classes\Menus\MenuHelper;
use App\Enums\Subcategory;
use Carbon\Carbon;
use Exception;

/**
 * Validates order composition rules for individual agreement users.
 * 
 * This validation ensures that orders comply with menu structure requirements:
 * 
 * When validate_subcategory_rules is TRUE (complex validation):
 * - Categories WITHOUT subcategories: Must have exactly one product each
 * - Categories WITH subcategories: Validated through subcategory rules
 * - Required subcategories (PLATO DE FONDO, ENTRADA, SANDWICH, PAN DE ACOMPAÑAMIENTO): 
 *   Must have at least one product if present in menu
 * - All subcategories: Maximum one product per subcategory type
 * 
 * When validate_subcategory_rules is FALSE (simplified validation):
 * - ALL categories: Must have exactly one product each (no subcategory logic)
 * 
 * Error messages are dynamically generated showing specific missing types/categories.
 * Comprehensive logging is provided for debugging validation logic.
 * 
 * Only applies to individual agreement users (IsAgreementIndividual = true).
 */
class OneProductPerCategory extends OrderStatusValidation
{
    /**
     * Friendly names for subcategories to make error messages more user-friendly
     */
    private const FRIENDLY_SUBCATEGORY_NAMES = [
        'PLATO DE FONDO' => 'plato principal',
        'ENTRADA' => 'entrada',
        'SANDWICH' => 'sandwich',
        'PAN DE ACOMPANAMIENTO' => 'pan de acompañamiento',
        'POSTRE' => 'postre',
        'BEBESTIBLE' => 'bebida',
        'CALIENTE' => 'comida caliente',
        'HIPOCALORICO' => 'opción ligera',
        'FRIA' => 'comida fría',
        'CUBIERTOS' => 'cubiertos'
    ];

    protected function check(Order $order, User $user, Carbon $date): void
    {
        if (UserPermissions::IsAgreementIndividual($user)) {
            $currentMenu = MenuHelper::getCurrentMenuQuery($date, $user)->first();

            if (!$currentMenu) {
                throw new Exception("No se encontró un menú activo para la fecha");
            }

            $categoryMenus = $currentMenu->categoryMenus()
                ->where('is_active', true)
                ->whereHas('category.products.priceListLines', function ($query) use ($user) {
                    $query->where('active', true)
                        ->whereHas('priceList', function ($priceListQuery) use ($user) {
                            $priceListQuery->where('id', $user->company->price_list_id);
                        });
                })
                ->orderedByDisplayOrder()
                ->get();

            $categoriesInOrder = $order->orderLines->map(function ($orderLine) {
                return [
                    'category' => $orderLine->product->category,
                    'product_name' => $orderLine->product->name,
                ];
            });

            $groupedByCategory = $categoriesInOrder->groupBy(function ($item) {
                return $item['category']->id;
            });

            if ($user->validate_subcategory_rules) {
                $this->validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder, $groupedByCategory);

                $subcategoriesInOrder = $this->buildSubcategoriesInOrder($categoriesInOrder);

                $this->validateCategoriesWithSubcategories($categoryMenus, $categoriesInOrder);

                $this->validateOneProductPerSubcategory($subcategoriesInOrder);
                
            } else {
                $this->validateSimplifiedCategoryRules($categoryMenus, $categoriesInOrder, $groupedByCategory);
            }
        }
    }

    /**
     * Validate that there is at least one product in the order for each required subcategory.
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @throws Exception
     */
    protected function validateCategoriesWithSubcategories($categoryMenus, $categoriesInOrder): void
    {
        $subcategoriesInMenu = $categoryMenus
            ->flatMap(function ($categoryMenu) {
                return $categoryMenu->category->subcategories->pluck('name')->toArray();
            })
            ->unique();

        $subcategoriesInOrder = $categoriesInOrder
            ->flatMap(function ($item) {
                return $item['category']->subcategories->pluck('name')->toArray();
            })
            ->unique();

        $requiredSubcategories = [
            Subcategory::PLATO_DE_FONDO,
            Subcategory::ENTRADA,
            Subcategory::SANDWICH,
            Subcategory::PAN_DE_ACOMPANAMIENTO
        ];

        foreach ($requiredSubcategories as $requiredSubcategory) {
            $subcategoryValue = $requiredSubcategory->value;
            
            if ($subcategoriesInMenu->contains($subcategoryValue) && !$subcategoriesInOrder->contains($subcategoryValue)) {
                throw new Exception($this->generateMissingSubcategoryMessage($subcategoryValue));
            }
        }
    }

    /**
     * Validate categories without subcategories
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @param \Illuminate\Support\Collection $groupedByCategory
     * @throws Exception
     */
    protected function validateCategoriesWithoutSubcategories($categoryMenus, $categoriesInOrder, $groupedByCategory): void
    {
        foreach ($categoryMenus as $categoryMenu) {
            $category = $categoryMenu->category;

            if ($category->subcategories->isEmpty()) {

                $hasProductInCategory = $this->checkProductInCategory($category, $categoriesInOrder);

                if (!$hasProductInCategory) {
                    
                    $allowedSubcategories = [
                        Subcategory::PLATO_DE_FONDO->value,
                        Subcategory::ENTRADA->value,
                        Subcategory::SANDWICH->value,
                        Subcategory::PAN_DE_ACOMPANAMIENTO->value
                    ];
                    
                    $subcategoriesInMenu = $categoryMenus
                        ->flatMap(function ($categoryMenu) {
                            return $categoryMenu->category->subcategories->pluck('name')->toArray();
                        })
                        ->filter(function ($subcategory) use ($allowedSubcategories) {
                            return in_array($subcategory, $allowedSubcategories);
                        })
                        ->unique()
                        ->sort()
                        ->values()
                        ->toArray();
                    
                    $categoriesWithoutSubcategories = $categoryMenus
                        ->filter(function ($categoryMenu) {
                            return $categoryMenu->category->subcategories->isEmpty();
                        })
                        ->map(function ($categoryMenu) {
                            return $categoryMenu->category->name;
                        })
                        ->unique()
                        ->sort()
                        ->values()
                        ->toArray();
                    
                    $message = $this->generateMissingItemsMessage($subcategoriesInMenu, $categoriesWithoutSubcategories);
                    
                    throw new Exception($message);
                }

                $this->validateOneProductPerCategory($category, $groupedByCategory);
            }
        }
    }

    /**
     * Validate that there is no more than one product per subcategory
     *
     * @param \Illuminate\Support\Collection $subcategoriesInOrder
     * @throws Exception
     */
    protected function validateOneProductPerSubcategory($subcategoriesInOrder): void
    {
        foreach ($subcategoriesInOrder as $subcategoryName => $products) {
            if ($products->count() > 1) {
                $productNames = $products->pluck('product_name')->toArray();
                throw new Exception(
                    $this->generateTooManySubcategoryMessage($subcategoryName, $productNames)
                );
            }
        }
    }

    /**
     * Build subcategories in order collection
     *
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @return \Illuminate\Support\Collection
     */
    protected function buildSubcategoriesInOrder($categoriesInOrder)
    {
        return $categoriesInOrder
            ->filter(function ($item) {
                return !$item['category']->subcategories->isEmpty();
            })
            ->flatMap(function ($item) {
                return $item['category']->subcategories->map(function ($subcategory) use ($item) {
                    return [
                        'subcategory_name' => $subcategory->name,
                        'product_name' => $item['product_name'],
                        'category_name' => $item['category']->name,
                    ];
                });
            })
            ->groupBy('subcategory_name');
    }

    /**
     * Check if there is at least one product in a specific category
     *
     * @param \App\Models\Category $category
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @return bool
     */
    protected function checkProductInCategory($category, $categoriesInOrder): bool
    {
        return $categoriesInOrder->contains(function ($item) use ($category) {
            return $item['category']->id === $category->id;
        });
    }

    /**
     * Validate that there is only one product per category
     *
     * @param \App\Models\Category $category
     * @param \Illuminate\Support\Collection $groupedByCategory
     * @throws Exception
     */
    protected function validateOneProductPerCategory($category, $groupedByCategory): void
    {
        $productsInCategory = $groupedByCategory->get($category->id, []);
        if ($productsInCategory->count() > 1) {
            $productNames = $productsInCategory->pluck('product_name')->toArray();
            throw new Exception(
                $this->generateTooManyProductsMessage($category->name, $productNames)
            );
        }
    }

    /**
     * Validate simplified category rules (when validate_subcategory_rules is false)
     *
     * @param \Illuminate\Support\Collection $categoryMenus
     * @param \Illuminate\Support\Collection $categoriesInOrder
     * @param \Illuminate\Support\Collection $groupedByCategory
     * @throws Exception
     */
    protected function validateSimplifiedCategoryRules($categoryMenus, $categoriesInOrder, $groupedByCategory): void
    {
        foreach ($categoryMenus as $categoryMenu) {
            $category = $categoryMenu->category;

            $hasProductInCategory = $this->checkProductInCategory($category, $categoriesInOrder);

            if (!$hasProductInCategory) {
                throw new Exception($this->generateSimpleMissingCategoryMessage($category->name));
            }

            $this->validateOneProductPerCategory($category, $groupedByCategory);
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
     * Generate user-friendly message for missing required items
     */
    private function generateMissingItemsMessage(array $missingSubcategories, array $missingCategories): string
    {
        $message = "🍽️ Tu menú necesita algunos elementos para estar completo:\n\n";
        
        if (!empty($missingSubcategories)) {
            $message .= "📋 Tipos de comida requeridos:\n";
            foreach ($missingSubcategories as $subcategory) {
                $friendlyName = $this->getFriendlySubcategoryName($subcategory);
                $message .= "  • " . ucfirst($friendlyName) . "\n";
            }
        }
        
        if (!empty($missingCategories)) {
            $message .= "\n🍽️ Categorías requeridas:\n";
            foreach ($missingCategories as $category) {
                $message .= "  • " . $category . "\n";
            }
        }
        
        $message .= "\n💡 Consejo: Un menú balanceado incluye todos estos elementos para una experiencia completa.";
        
        return $message;
    }

    /**
     * Generate user-friendly message for too many products in category
     */
    private function generateTooManyProductsMessage(string $categoryName, array $productNames): string
    {
        $products = implode(', ', $productNames);
        
        return "🚫 Solo puedes elegir un producto de {$categoryName}.\n\n" .
               "Actualmente tienes seleccionados: {$products}\n\n" .
               "💡 Consejo: Mantén tu menú balanceado eligiendo solo una opción por tipo de comida.";
    }

    /**
     * Generate user-friendly message for missing subcategory
     */
    private function generateMissingSubcategoryMessage(string $subcategory): string
    {
        $friendlyName = $this->getFriendlySubcategoryName($subcategory);
        
        return "🍽️ Tu menú está casi listo, pero falta algo importante:\n\n" .
               "Necesitas elegir " . ($friendlyName === 'entrada' ? 'una' : 'un') . " {$friendlyName} para completar tu pedido.\n\n" .
               "💡 Sugerencia: Revisa las opciones disponibles en esa sección y elige la que más te guste.";
    }

    /**
     * Generate user-friendly message for missing category (simplified validation)
     */
    private function generateSimpleMissingCategoryMessage(string $categoryName): string
    {
        return "🍽️ Tu pedido necesita incluir algo de {$categoryName}.\n\n" .
               "📋 Para tener un menú completo, cada categoría debe tener al menos un producto.\n\n" .
               "💡 Consejo: Elige una opción de {$categoryName} que te guste para continuar.";
    }

    /**
     * Generate user-friendly message for too many products in subcategory
     */
    private function generateTooManySubcategoryMessage(string $subcategoryName, array $productNames): string
    {
        return "🍽️ Para mantener el equilibrio de tu menú, solo puedes elegir un {$subcategoryName} por pedido.\n\n" .
               "💡 Esto nos permite ofrecerte mayor variedad en tu menú.";
    }
}