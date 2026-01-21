<?php

namespace Tests\Feature\API\V1\Cafe;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Menu;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\CategoryLine;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test for Conditional Validations with Dynamic Category Products
 *
 * This test validates that the 3 conditional validation classes work correctly
 * when users order products that are DISPLAYED in a dynamic category but
 * BELONG to their original category (via category_id).
 *
 * DYNAMIC CATEGORY BEHAVIOR:
 * - Products have their original category_id (e.g., category_id = 1 for "SNA PRODUCTS")
 * - Products are DISPLAYED in dynamic category (e.g., "Productos más vendidos")
 * - Validations use $orderLine->product->category which returns ORIGINAL category
 * - Therefore, validations should work correctly with dynamic category products
 *
 * VALIDATIONS TESTED:
 * 1. MenuExistsValidation - requires allow_late_orders = true
 * 2. MaxOrderAmountValidation - requires validate_min_price = true
 * 3. DispatchRulesCategoriesValidation - requires allow_late_orders = true
 */
class DynamicCategoryConditionalValidationsTest extends TestCase
{
    use RefreshDatabase;

    protected Role $cafeRole;
    protected Permission $consolidadoPermission;
    protected Company $company;
    protected PriceList $priceList;
    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Create common data for all tests
        $this->cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $this->consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $this->company = Company::create([
            'name' => 'TEST DYNAMIC CATEGORY COMPANY',
            'fantasy_name' => 'TEST DYNAMIC',
            'address' => 'Test Address 123',
            'email' => 'test@dynamic.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG-DYN-001',
            'description' => 'Test company for dynamic category validations',
            'active' => true,
            'tax_id' => '123456789',
        ]);

        $this->priceList = PriceList::create([
            'name' => 'TEST DYNAMIC PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $this->company->update(['price_list_id' => $this->priceList->id]);

        $this->branch = Branch::create([
            'name' => 'TEST DYNAMIC BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $this->company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Helper: Create a user with specific settings
     */
    protected function createUser(array $attributes = []): User
    {
        $defaults = [
            'name' => 'TEST DYNAMIC USER',
            'nickname' => 'TEST.DYNAMIC',
            'email' => 'testdynamic@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'active' => true,
            'validate_subcategory_rules' => false,
            'allow_late_orders' => false,
            'validate_min_price' => false,
        ];

        $user = User::create(array_merge($defaults, $attributes));
        $user->roles()->attach($this->cafeRole->id);
        $user->permissions()->attach($this->consolidadoPermission->id);

        return $user;
    }

    /**
     * Helper: Create the original category (where products belong)
     */
    protected function createOriginalCategory(array $weekdaysWithCategoryLines = []): Category
    {
        $category = Category::create([
            'name' => 'TEST ORIGINAL CATEGORY (SNA)',
            'description' => 'Original category where products belong',
            'is_active' => true,
            'is_dynamic' => false,
        ]);

        // Create CategoryLines for specified weekdays
        foreach ($weekdaysWithCategoryLines as $weekday) {
            CategoryLine::create([
                'category_id' => $category->id,
                'weekday' => $weekday,
                'preparation_days' => 1,
                'maximum_order_time' => '15:00:00',
                'active' => true,
            ]);
        }

        return $category;
    }

    /**
     * Helper: Get or create the dynamic category (where products are displayed)
     * Note: Migration may have already created this category
     */
    protected function createDynamicCategory(): Category
    {
        return Category::firstOrCreate(
            ['is_dynamic' => true],
            [
                'name' => 'Productos más vendidos',
                'description' => 'Dynamic category for best-selling products',
                'is_active' => true,
            ]
        );
    }

    /**
     * Helper: Create a product that belongs to original category
     */
    protected function createProduct(Category $originalCategory, int $price = 500000): Product
    {
        $product = Product::create([
            'name' => 'TEST - BEST SELLING PRODUCT',
            'code' => 'TEST-BSP-001',
            'description' => 'Product from original category displayed in dynamic category',
            'category_id' => $originalCategory->id, // KEY: Belongs to ORIGINAL category
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $this->priceList->id,
            'product_id' => $product->id,
            'unit_price' => $price,
            'active' => true,
        ]);

        return $product;
    }

    /**
     * Helper: Create menu with both original and dynamic category
     */
    protected function createMenuWithDynamicCategory(
        Category $originalCategory,
        Category $dynamicCategory,
        Product $product,
        string $publicationDate,
        string $maxOrderDate
    ): Menu {
        $menu = Menu::create([
            'title' => 'TEST MENU WITH DYNAMIC CATEGORY',
            'description' => null,
            'publication_date' => $publicationDate,
            'max_order_date' => $maxOrderDate,
            'role_id' => $this->cafeRole->id,
            'permissions_id' => $this->consolidadoPermission->id,
            'active' => true,
        ]);

        // Create CategoryMenu for original category
        CategoryMenu::create([
            'category_id' => $originalCategory->id,
            'menu_id' => $menu->id,
            'display_order' => 100,
            'show_all_products' => true,
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // Create CategoryMenu for dynamic category (display_order = 0 to show first)
        $dynamicCategoryMenu = CategoryMenu::create([
            'category_id' => $dynamicCategory->id,
            'menu_id' => $menu->id,
            'display_order' => 0, // First position
            'show_all_products' => false, // Only specific products
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // Attach product to dynamic category menu (this is how products appear in dynamic category)
        $dynamicCategoryMenu->products()->attach($product->id, ['display_order' => 1]);

        return $menu;
    }

    // =========================================================================
    // TEST 1: MenuExistsValidation with Dynamic Category Products
    // =========================================================================

    /**
     * Test that MenuExistsValidation fails when menu max_order_date has expired
     * and user orders a product displayed in dynamic category.
     *
     * SCENARIO:
     * - User has allow_late_orders = true (triggers MenuExistsValidation)
     * - Menu max_order_date has passed
     * - Product belongs to original category but displayed in dynamic category
     *
     * EXPECTED:
     * - API returns 422: "No hay un menú disponible para esta fecha de despacho"
     * - Validation uses product's ORIGINAL category (works correctly)
     */
    public function test_menu_exists_validation_fails_with_dynamic_category_product_when_menu_expired(): void
    {
        // Set time AFTER max_order_date
        Carbon::setTestNow(Carbon::parse('2026-01-22 10:00:00'));

        // Create user with allow_late_orders = true
        $user = $this->createUser([
            'allow_late_orders' => true,
        ]);

        // Create categories
        $originalCategory = $this->createOriginalCategory(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
        $dynamicCategory = $this->createDynamicCategory();

        // Create product (belongs to original category)
        $product = $this->createProduct($originalCategory);

        // Create menu with EXPIRED max_order_date
        $this->createMenuWithDynamicCategory(
            $originalCategory,
            $dynamicCategory,
            $product,
            publicationDate: '2026-01-22',
            maxOrderDate: '2026-01-20 15:30:00' // EXPIRED (current time is 2026-01-22)
        );

        // Authenticate and make request
        Sanctum::actingAs($user);

        $payload = [
            'order_lines' => [
                [
                    'id' => $product->id,
                    'quantity' => 1,
                    'partially_scheduled' => false,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/orders/create-or-update-order/2026-01-22', $payload);

        // ASSERTIONS
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    'No hay un menú disponible para esta fecha de despacho',
                ],
            ],
        ]);

        // Verify no order was created
        $this->assertDatabaseMissing('orders', [
            'user_id' => $user->id,
        ]);
    }

    // =========================================================================
    // TEST 2: MaxOrderAmountValidation with Dynamic Category Products
    // =========================================================================

    /**
     * Test that MaxOrderAmountValidation fails when order total is below minimum
     * and user orders a product displayed in dynamic category.
     *
     * SCENARIO:
     * - User has validate_min_price = true (triggers MaxOrderAmountValidation)
     * - Branch has min_price_order = $70,000
     * - Order total = $5,000 (below minimum)
     * - Product belongs to original category but displayed in dynamic category
     *
     * EXPECTED:
     * - API returns 422: "El monto del pedido mínimo es $70.000,00"
     */
    public function test_max_order_amount_validation_fails_with_dynamic_category_product_below_minimum(): void
    {
        // Set time BEFORE max_order_date
        Carbon::setTestNow(Carbon::parse('2026-01-20 10:00:00'));

        // Update branch with minimum price
        $this->branch->update(['min_price_order' => 7000000]); // $70,000.00

        // Create user with validate_min_price = true
        $user = $this->createUser([
            'allow_late_orders' => true,
            'validate_min_price' => true,
        ]);

        // Create categories
        $originalCategory = $this->createOriginalCategory(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
        $dynamicCategory = $this->createDynamicCategory();

        // Create product with LOW price (below minimum)
        $product = $this->createProduct($originalCategory, price: 500000); // $5,000.00

        // Create menu with valid max_order_date
        $this->createMenuWithDynamicCategory(
            $originalCategory,
            $dynamicCategory,
            $product,
            publicationDate: '2026-01-22',
            maxOrderDate: '2026-01-21 15:30:00' // Valid (current time is 2026-01-20)
        );

        // Create order with low total
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => '2026-01-22',
            'status' => OrderStatus::PENDING->value,
            'total' => 500000, // $5,000.00 (below $70,000 minimum)
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 500000,
            'partially_scheduled' => false,
        ]);

        // Authenticate and make request to update status
        Sanctum::actingAs($user);

        $payload = [
            'status' => 'PROCESSED',
        ];

        $response = $this->postJson('/api/v1/orders/update-order-status/2026-01-22', $payload);

        // ASSERTIONS
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    'El monto del pedido mínimo es $70.000,00',
                ],
            ],
        ]);

        // Verify order status was NOT updated
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PENDING->value,
        ]);
    }

    // =========================================================================
    // TEST 3: DispatchRulesCategoriesValidation with Dynamic Category Products
    // =========================================================================

    /**
     * Test that DispatchRulesCategoriesValidation fails when product's category
     * has no CategoryLine for the dispatch day.
     *
     * SCENARIO:
     * - User has allow_late_orders = true (triggers DispatchRulesCategoriesValidation)
     * - Dispatch date is Thursday (2026-01-22)
     * - Product's ORIGINAL category has NO CategoryLine for Thursday
     * - Product is displayed in dynamic category
     *
     * EXPECTED:
     * - API returns 422: "El producto '...' no está disponible para el día Jueves."
     * - Validation correctly uses product's ORIGINAL category to check availability
     */
    public function test_dispatch_rules_validation_fails_with_dynamic_category_product_not_available(): void
    {
        // Set time BEFORE max_order_date (Thursday 2026-01-22 is the dispatch date)
        Carbon::setTestNow(Carbon::parse('2026-01-20 10:00:00'));

        // Create user with allow_late_orders = true
        $user = $this->createUser([
            'allow_late_orders' => true,
        ]);

        // Create original category with CategoryLines ONLY for Monday, Tuesday, Wednesday
        // NO CategoryLine for Thursday (the dispatch day)
        $originalCategory = $this->createOriginalCategory(['monday', 'tuesday', 'wednesday']);
        $dynamicCategory = $this->createDynamicCategory();

        // Create product (belongs to original category)
        $product = $this->createProduct($originalCategory);

        // Create menu with valid max_order_date
        $this->createMenuWithDynamicCategory(
            $originalCategory,
            $dynamicCategory,
            $product,
            publicationDate: '2026-01-22', // Thursday
            maxOrderDate: '2026-01-21 15:30:00'
        );

        // Authenticate and make request
        Sanctum::actingAs($user);

        $payload = [
            'order_lines' => [
                [
                    'id' => $product->id,
                    'quantity' => 1,
                    'partially_scheduled' => false,
                ],
            ],
        ];

        // 2026-01-22 is Thursday
        $response = $this->postJson('/api/v1/orders/create-or-update-order/2026-01-22', $payload);

        // ASSERTIONS
        $response->assertStatus(422);

        // The error message should mention the product is not available for Thursday
        $responseData = $response->json();
        $this->assertStringContainsString(
            'no está disponible para el día',
            $responseData['errors']['message'][0] ?? ''
        );
    }

    // =========================================================================
    // POSITIVE TESTS: Validations PASS with Dynamic Category Products
    // =========================================================================

    /**
     * Test that all conditional validations PASS when conditions are met
     * and user orders a product displayed in dynamic category.
     *
     * SCENARIO:
     * - User has allow_late_orders = true, validate_min_price = true
     * - Menu is valid (not expired)
     * - Order total meets minimum price
     * - Product is available for dispatch day
     *
     * EXPECTED:
     * - API returns 200: Order created successfully
     */
    public function test_all_conditional_validations_pass_with_dynamic_category_product(): void
    {
        // Set time BEFORE max_order_date
        Carbon::setTestNow(Carbon::parse('2026-01-20 10:00:00'));

        // Update branch with minimum price
        $this->branch->update(['min_price_order' => 500000]); // $5,000.00

        // Create user with both validations enabled
        $user = $this->createUser([
            'allow_late_orders' => true,
            'validate_min_price' => true,
        ]);

        // Create original category with CategoryLine for Thursday
        $originalCategory = $this->createOriginalCategory(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
        $dynamicCategory = $this->createDynamicCategory();

        // Create product with price ABOVE minimum
        $product = $this->createProduct($originalCategory, price: 1000000); // $10,000.00

        // Create menu with valid max_order_date
        $this->createMenuWithDynamicCategory(
            $originalCategory,
            $dynamicCategory,
            $product,
            publicationDate: '2026-01-22', // Thursday
            maxOrderDate: '2026-01-21 15:30:00'
        );

        // Authenticate and make request
        Sanctum::actingAs($user);

        $payload = [
            'order_lines' => [
                [
                    'id' => $product->id,
                    'quantity' => 1,
                    'partially_scheduled' => false,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/orders/create-or-update-order/2026-01-22', $payload);

        // ASSERTIONS
        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Order updated successfully',
        ]);

        // Verify order was created
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'dispatch_date' => '2026-01-22',
        ]);

        // Verify order line was created with product from dynamic category display
        $order = Order::where('user_id', $user->id)->first();
        $this->assertEquals(1, $order->orderLines->count());
        $this->assertEquals($product->id, $order->orderLines->first()->product_id);

        // KEY ASSERTION: Product still belongs to ORIGINAL category
        $this->assertEquals($originalCategory->id, $order->orderLines->first()->product->category_id);
    }
}
