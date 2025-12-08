<?php

namespace Tests\Helpers;

use App\Enums\AdvanceOrderStatus;
use App\Enums\OrderStatus;
use App\Models\AdvanceOrder;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlatedDish;
use App\Models\PlatedDishIngredient;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\ProductionArea;
use App\Models\User;

/**
 * Test Data Factory
 *
 * Provides reusable helper methods to create test data for feature tests.
 * Designed to reduce boilerplate code and improve test readability.
 */
class TestDataFactory
{
    protected static ?PriceList $priceList = null;
    protected static ?ProductionArea $productionArea = null;

    /**
     * Reset static properties (useful between tests)
     */
    public static function reset(): void
    {
        self::$priceList = null;
        self::$productionArea = null;
    }

    /**
     * Create a price list
     */
    public static function createPriceList(array $attributes = []): PriceList
    {
        if (self::$priceList === null) {
            self::$priceList = PriceList::create(array_merge([
                'name' => 'Test Price List',
                'description' => 'Price list for testing',
            ], $attributes));
        }

        return self::$priceList;
    }

    /**
     * Create a company with price list
     */
    public static function createCompany(array $attributes = []): Company
    {
        $priceList = self::createPriceList();

        return Company::create(array_merge([
            'name' => 'TEST COMPANY S.A.',
            'fantasy_name' => 'TEST COMPANY',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCO',
            'email' => 'test@company.com',
            'price_list_id' => $priceList->id,
        ], $attributes));
    }

    /**
     * Create a branch for a company
     */
    public static function createBranch(Company $company, string $fantasyName, array $attributes = []): Branch
    {
        return Branch::create(array_merge([
            'company_id' => $company->id,
            'fantasy_name' => $fantasyName,
            'address' => "Address for {$fantasyName}",
            'min_price_order' => 0,
        ], $attributes));
    }

    /**
     * Create a user for a branch
     */
    public static function createUser(Company $company, Branch $branch, string $nickname, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => "User {$nickname}",
            'nickname' => $nickname,
            'email' => strtolower($nickname) . '@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
        ], $attributes));
    }

    /**
     * Create a production area
     */
    public static function createProductionArea(string $name = 'COCINA'): ProductionArea
    {
        if (self::$productionArea === null) {
            self::$productionArea = ProductionArea::create([
                'name' => $name,
                'description' => "Área de {$name}",
            ]);
        }

        return self::$productionArea;
    }

    /**
     * Create a category
     */
    public static function createCategory(string $name, array $attributes = []): Category
    {
        $productionArea = self::createProductionArea();

        return Category::create(array_merge([
            'name' => $name,
            'description' => "Category {$name}",
            'production_area_id' => $productionArea->id,
        ], $attributes));
    }

    /**
     * Create a product (ingredient or plated dish base)
     */
    public static function createProduct(Category $category, string $name, string $code, array $attributes = []): Product
    {
        $priceList = self::createPriceList();
        $productionArea = self::createProductionArea();

        $product = Product::create(array_merge([
            'name' => $name,
            'description' => "Product {$name}",
            'code' => $code,
            'category_id' => $category->id,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
            'active' => true,
            'stock' => 0,
        ], $attributes));

        // Attach to production area
        $product->productionAreas()->attach($productionArea->id);

        // Add to price list
        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => $attributes['price'] ?? 5000,
        ]);

        return $product;
    }

    /**
     * Create a plated dish with optional related product
     */
    public static function createPlatedDish(Product $product, bool $isHoreca, ?Product $relatedProduct = null): PlatedDish
    {
        return PlatedDish::create([
            'product_id' => $product->id,
            'is_horeca' => $isHoreca,
            'is_active' => true,
            'related_product_id' => $relatedProduct?->id,
        ]);
    }

    /**
     * Add ingredient to a plated dish
     */
    public static function addIngredient(
        PlatedDish $platedDish,
        Product $ingredient,
        float $quantity,
        string $measureUnit = 'GR',
        float $maxQuantityHoreca = 1000,
        int $orderIndex = 1
    ): PlatedDishIngredient {
        return PlatedDishIngredient::create([
            'plated_dish_id' => $platedDish->id,
            'ingredient_id' => $ingredient->id,
            'ingredient_name' => $ingredient->name,
            'quantity' => $quantity,
            'measure_unit' => $measureUnit,
            'max_quantity_horeca' => $maxQuantityHoreca,
            'order_index' => $orderIndex,
            'is_optional' => false,
            'shelf_life' => 3,
        ]);
    }

    /**
     * Create a HORECA order (PROCESSED status, ready for advance order)
     *
     * Creates Order with OrderLine. Use OrderRepository->createAdvanceOrderFromOrders()
     * to create the AdvanceOrder and all the pivot tables automatically.
     */
    public static function createHorecaOrder(
        User $user,
        Branch $branch,
        Product $product,
        int $quantity,
        string $deliveryDate
    ): Order {
        // Get product price
        $unitPrice = PriceListLine::where('product_id', $product->id)->value('unit_price') ?? 5000;

        // Create order with PROCESSED status
        $order = Order::create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::PROCESSED,
            'dispatch_date' => $deliveryDate,
            'date' => $deliveryDate,
            'order_number' => 'ORD-' . strtoupper($branch->fantasy_name) . '-' . now()->timestamp,
            'total' => $unitPrice * $quantity,
            'total_with_tax' => ($unitPrice * $quantity) * 1.19,
            'tax_amount' => ($unitPrice * $quantity) * 0.19,
            'grand_total' => ($unitPrice * $quantity) * 1.19,
            'dispatch_cost' => 0,
        ]);

        // Create order line
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $unitPrice * $quantity,
        ]);

        return $order;
    }

    /**
     * Create a regular (non-advance) order for individual products
     */
    public static function createIndividualOrder(
        User $user,
        Product $product,
        int $quantity,
        string $deliveryDate
    ): Order {
        $order = Order::create([
            'user_id' => $user->id,
            'date' => $deliveryDate,
            'status' => OrderStatus::PENDING->value,
            'is_advance_order' => false, // Regular order
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => PriceListLine::where('product_id', $product->id)->value('unit_price'),
        ]);

        return $order;
    }
}