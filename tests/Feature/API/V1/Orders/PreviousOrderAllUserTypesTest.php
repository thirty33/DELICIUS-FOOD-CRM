<?php

namespace Tests\Feature\API\V1\Orders;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Category;
use App\Models\CategoryLine;
use App\Models\CategoryMenu;
use App\Models\Menu;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Subcategory;
use App\Models\OrderRule;
use App\Models\OrderRuleExclusion;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test: Previous Order + Create Order - All User Types
 *
 * Validates the full flow (GET previous order → POST create-or-update-order)
 * across all three user types with three delegation scenarios:
 *
 * USER TYPES:
 * 1. Café / Consolidado (Role: Café, Permission: Consolidado)
 * 2. Convenio / Individual (Role: Convenio, Permission: Individual)
 * 3. Convenio / Consolidado (Role: Convenio, Permission: Consolidado)
 *
 * SCENARIOS PER TYPE:
 * A. Regular user → triggers validation error (422)
 * B. Master user delegating → triggers validation error (422)
 * C. Super master user delegating → bypasses validations (200)
 *
 * VALIDATION TRIGGERS:
 * - Convenio/Individual: OneProductPerCategorySimple (2 products in same category without subcategories)
 * - Convenio/Consolidado: PolymorphicExclusion (2 products violating exclusion rule)
 * - Café/Consolidado: DispatchRulesCategoriesValidation (product not available for dispatch day)
 *
 * SUPER MASTER BYPASS:
 * OrderStatusValidation::validate() skips ALL checks when userForValidations->super_master_user is true.
 *
 * API ENDPOINTS:
 * GET /api/v1/orders/get-previous-order/{date}?delegate_user={nickname}
 * POST /api/v1/orders/create-or-update-order/{date}?delegate_user={nickname}
 */
class PreviousOrderAllUserTypesTest extends TestCase
{
    use RefreshDatabase;

    private Role $cafeRole;
    private Role $agreementRole;
    private Permission $consolidadoPermission;
    private Permission $individualPermission;

    protected function setUp(): void
    {
        parent::setUp();
        // Dispatch date is 2026-02-01 (sunday)
        // "Now" is 2026-02-01 10:00 so menus with publication_date=2026-02-01 are valid
        Carbon::setTestNow(Carbon::parse('2026-02-01 10:00:00'));

        $this->cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $this->agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $this->consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);
        $this->individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =====================================================================
    // HELPER METHODS
    // =====================================================================

    private function createCompanyWithPriceList(string $name = 'TEST COMPANY'): array
    {
        $priceList = PriceList::create([
            'name' => "{$name} PRICE LIST",
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company = Company::create([
            'name' => "{$name} S.A.",
            'fantasy_name' => $name,
            'address' => 'Test Address 123',
            'email' => strtolower(str_replace(' ', '', $name)) . '@test.com',
            'phone_number' => '555000111',
            'registration_number' => 'REG-' . strtoupper(substr(md5($name), 0, 6)),
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '555' . rand(100000, 999999),
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => "{$name} BRANCH",
            'address' => 'Test Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        return [$priceList, $company, $branch];
    }

    private function createUser(
        Role $role,
        Permission $permission,
        Company $company,
        Branch $branch,
        string $nickname,
        array $overrides = []
    ): User {
        $defaults = [
            'name' => str_replace('.', ' ', $nickname),
            'nickname' => $nickname,
            'email' => strtolower(str_replace('.', '', $nickname)) . '@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'phone_number' => '555' . rand(100000, 999999),
            'active' => true,
            'master_user' => false,
            'super_master_user' => false,
            'allow_late_orders' => true,
            'validate_subcategory_rules' => false,
        ];

        $user = User::create(array_merge($defaults, $overrides));
        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        return $user;
    }

    private function createProduct(
        string $name,
        Category $category,
        PriceList $priceList,
        float $price = 500000
    ): Product {
        $product = Product::create([
            'name' => $name,
            'description' => "Test product: {$name}",
            'code' => 'PROD-' . strtoupper(substr(md5($name), 0, 8)),
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => $price,
            'active' => true,
        ]);

        return $product;
    }

    private function createMenu(Role $role, Permission $permission): Menu
    {
        return Menu::create([
            'title' => "TEST MENU {$role->name} {$permission->name}",
            'description' => 'Test menu',
            'publication_date' => '2026-02-01',
            'max_order_date' => '2026-02-01 23:59:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);
    }

    private function createCategoryWithMenu(
        string $name,
        Menu $menu,
        int $order,
        bool $showAllProducts = true
    ): array {
        $category = Category::create([
            'name' => $name,
            'description' => "Test category: {$name}",
            'active' => true,
        ]);

        // CategoryLine for sunday (dispatch day)
        CategoryLine::create([
            'category_id' => $category->id,
            'weekday' => 'sunday',
            'preparation_days' => 0,
            'maximum_order_time' => '23:59:00',
            'active' => true,
        ]);

        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => $order,
            'show_all_products' => $showAllProducts,
        ]);

        return [$category, $categoryMenu];
    }

    private function createPreviousOrder(User $user, array $products, string $dispatchDate = '2026-01-28'): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::PROCESSED->value,
            'branch_id' => $user->branch_id,
            'dispatch_date' => Carbon::parse($dispatchDate),
        ]);

        foreach ($products as $product) {
            $qty = is_array($product) ? $product['quantity'] : 1;
            $prod = is_array($product) ? $product['product'] : $product;

            OrderLine::create([
                'order_id' => $order->id,
                'product_id' => $prod->id,
                'quantity' => $qty,
                'unit_price' => 500000,
            ]);
        }

        return $order;
    }

    private function getPreviousOrder(string $date, ?string $delegateUser = null): \Illuminate\Testing\TestResponse
    {
        $url = "/api/v1/orders/get-previous-order/{$date}";
        if ($delegateUser) {
            $url .= "?delegate_user={$delegateUser}";
        }
        return $this->getJson($url);
    }

    private function createOrderFromPreviousLines(
        array $previousOrderLines,
        string $date,
        ?string $delegateUser = null
    ): \Illuminate\Testing\TestResponse {
        $orderLines = array_map(function ($line) {
            return [
                'id' => $line['product_id'],
                'quantity' => $line['quantity'],
            ];
        }, $previousOrderLines);

        $url = "/api/v1/orders/create-or-update-order/{$date}";
        if ($delegateUser) {
            $url .= "?delegate_user={$delegateUser}";
        }

        return $this->postJson($url, ['order_lines' => $orderLines]);
    }

    // =====================================================================
    // 1. CONVENIO / INDIVIDUAL
    // Validation: OneProductPerCategorySimple
    // Trigger: 2 products from same category WITHOUT subcategories
    // =====================================================================

    /**
     * Convenio/Individual regular user: previous order with 2 products from
     * same category (no subcategories) triggers OneProductPerCategorySimple → 422
     */
    public function test_convenio_individual_regular_user_triggers_validation_error(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CONV IND');
        $menu = $this->createMenu($this->agreementRole, $this->individualPermission);

        // Category WITHOUT subcategories (triggers OneProductPerCategorySimple)
        [$category, $categoryMenu] = $this->createCategoryWithMenu('POSTRES', $menu, 1);

        $product1 = $this->createProduct('Flan', $category, $priceList);
        $product2 = $this->createProduct('Gelatina', $category, $priceList);
        $categoryMenu->products()->attach([$product1->id, $product2->id]);

        $user = $this->createUser(
            $this->agreementRole,
            $this->individualPermission,
            $company,
            $branch,
            'TEST.CONVIND.USER'
        );

        // Previous order has 2 products from same category
        $previousOrder = $this->createPreviousOrder($user, [$product1, $product2]);

        Sanctum::actingAs($user);

        // Step 1: GET previous order
        $getResponse = $this->getPreviousOrder('2026-02-01');
        $getResponse->assertStatus(200);
        $this->assertEquals($previousOrder->id, $getResponse->json('data.id'));

        // Step 2: POST create order with those products → 422
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01'
        );
        $postResponse->assertStatus(422);
    }

    /**
     * Convenio/Individual master user delegating: same validation triggers → 422
     */
    public function test_convenio_individual_master_user_triggers_validation_error(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CONV IND MASTER');
        $menu = $this->createMenu($this->agreementRole, $this->individualPermission);

        [$category, $categoryMenu] = $this->createCategoryWithMenu('POSTRES', $menu, 1);

        $product1 = $this->createProduct('Flan Master', $category, $priceList);
        $product2 = $this->createProduct('Gelatina Master', $category, $priceList);
        $categoryMenu->products()->attach([$product1->id, $product2->id]);

        $masterUser = $this->createUser(
            $this->agreementRole,
            $this->individualPermission,
            $company,
            $branch,
            'TEST.CONVIND.MASTER',
            ['master_user' => true]
        );

        $delegateUser = $this->createUser(
            $this->agreementRole,
            $this->individualPermission,
            $company,
            $branch,
            'TEST.CONVIND.DELEGATE'
        );

        $previousOrder = $this->createPreviousOrder($delegateUser, [$product1, $product2]);

        Sanctum::actingAs($masterUser);

        // Step 1: GET previous order for delegate
        $getResponse = $this->getPreviousOrder('2026-02-01', 'TEST.CONVIND.DELEGATE');
        $getResponse->assertStatus(200);
        $this->assertEquals($previousOrder->id, $getResponse->json('data.id'));

        // Step 2: POST create order for delegate → 422
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01',
            'TEST.CONVIND.DELEGATE'
        );
        $postResponse->assertStatus(422);

        // Verify master user has no order for this date (transaction rolled back)
        $masterOrder = Order::where('user_id', $masterUser->id)
            ->where('dispatch_date', '2026-02-01')
            ->first();
        $this->assertNull($masterOrder, 'Master user must NOT have an order for this date');
    }

    /**
     * Convenio/Individual super master user delegating: bypasses validations → 200
     */
    public function test_convenio_individual_super_master_bypasses_validations(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CONV IND SUPER');
        $menu = $this->createMenu($this->agreementRole, $this->individualPermission);

        [$category, $categoryMenu] = $this->createCategoryWithMenu('POSTRES', $menu, 1);

        $product1 = $this->createProduct('Flan Super', $category, $priceList);
        $product2 = $this->createProduct('Gelatina Super', $category, $priceList);
        $categoryMenu->products()->attach([$product1->id, $product2->id]);

        // Super master user in a DIFFERENT company (can delegate to any company)
        [$smPriceList, $smCompany, $smBranch] = $this->createCompanyWithPriceList('SUPER MASTER CO');

        $superMasterUser = $this->createUser(
            $this->agreementRole,
            $this->individualPermission,
            $smCompany,
            $smBranch,
            'TEST.CONVIND.SUPERMASTER',
            ['master_user' => true, 'super_master_user' => true]
        );

        $delegateUser = $this->createUser(
            $this->agreementRole,
            $this->individualPermission,
            $company,
            $branch,
            'TEST.CONVIND.DELEGATE.SM'
        );

        $previousOrder = $this->createPreviousOrder($delegateUser, [$product1, $product2]);

        Sanctum::actingAs($superMasterUser);

        // Step 1: GET previous order for delegate
        $getResponse = $this->getPreviousOrder('2026-02-01', 'TEST.CONVIND.DELEGATE.SM');
        $getResponse->assertStatus(200);

        // Step 2: POST create order for delegate → 200 (super master bypasses validations)
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01',
            'TEST.CONVIND.DELEGATE.SM'
        );
        $postResponse->assertStatus(200);

        // Verify order belongs to delegate
        $createdOrderId = $postResponse->json('data.id');
        $createdOrder = Order::find($createdOrderId);
        $this->assertEquals($delegateUser->id, $createdOrder->user_id);
        $this->assertNotEquals($superMasterUser->id, $createdOrder->user_id);

        // Verify both products are in the order
        $this->assertEquals(2, $createdOrder->orderLines->count());
    }

    // =====================================================================
    // 2. CONVENIO / CONSOLIDADO
    // Validation: PolymorphicExclusion
    // Trigger: OrderRule with Category→Category exclusion + 2 products
    //          from excluded categories
    // =====================================================================

    /**
     * Convenio/Consolidado regular user: previous order with products violating
     * polymorphic exclusion rule triggers PolymorphicExclusion → 422
     */
    public function test_convenio_consolidado_regular_user_triggers_validation_error(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CONV CONS');
        $menu = $this->createMenu($this->agreementRole, $this->consolidadoPermission);

        // Two categories with subcategories (for polymorphic exclusion)
        [$catA, $cmA] = $this->createCategoryWithMenu('ENSALADAS', $menu, 1);
        [$catB, $cmB] = $this->createCategoryWithMenu('POSTRES CONS', $menu, 2);

        // Attach subcategories so categories are recognized in validation
        $subEntrada = Subcategory::create(['name' => 'ENTRADA']);
        $catA->subcategories()->attach($subEntrada->id);
        $subPostre = Subcategory::create(['name' => 'POSTRE']);
        $catB->subcategories()->attach($subPostre->id);

        $productA = $this->createProduct('Ensalada Cesar', $catA, $priceList);
        $productB = $this->createProduct('Torta Chocolate', $catB, $priceList);
        $cmA->products()->attach($productA->id);
        $cmB->products()->attach($productB->id);

        // Create OrderRule: Category A excludes Category B
        $orderRule = OrderRule::create([
            'name' => 'Test Exclusion Rule',
            'description' => 'Test: ENSALADAS excludes POSTRES CONS',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $this->agreementRole->id,
            'permission_id' => $this->consolidadoPermission->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        // Polymorphic exclusion: Category → Category
        OrderRuleExclusion::create([
            'order_rule_id' => $orderRule->id,
            'source_type' => Category::class,
            'source_id' => $catA->id,
            'excluded_type' => Category::class,
            'excluded_id' => $catB->id,
        ]);

        $user = $this->createUser(
            $this->agreementRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CONVCONS.USER',
            ['validate_subcategory_rules' => true]
        );

        // Previous order has products from both excluded categories
        $previousOrder = $this->createPreviousOrder($user, [$productA, $productB]);

        Sanctum::actingAs($user);

        // Step 1: GET previous order
        $getResponse = $this->getPreviousOrder('2026-02-01');
        $getResponse->assertStatus(200);
        $this->assertEquals($previousOrder->id, $getResponse->json('data.id'));

        // Step 2: POST create order → 422 (polymorphic exclusion violated)
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01'
        );
        $postResponse->assertStatus(422);
    }

    /**
     * Convenio/Consolidado master user delegating: same exclusion triggers → 422
     */
    public function test_convenio_consolidado_master_user_triggers_validation_error(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CONV CONS MASTER');
        $menu = $this->createMenu($this->agreementRole, $this->consolidadoPermission);

        [$catA, $cmA] = $this->createCategoryWithMenu('ENSALADAS M', $menu, 1);
        [$catB, $cmB] = $this->createCategoryWithMenu('POSTRES CONS M', $menu, 2);

        $subEntrada = Subcategory::create(['name' => 'ENTRADA M']);
        $catA->subcategories()->attach($subEntrada->id);
        $subPostre = Subcategory::create(['name' => 'POSTRE M']);
        $catB->subcategories()->attach($subPostre->id);

        $productA = $this->createProduct('Ensalada Cesar M', $catA, $priceList);
        $productB = $this->createProduct('Torta Chocolate M', $catB, $priceList);
        $cmA->products()->attach($productA->id);
        $cmB->products()->attach($productB->id);

        $orderRule = OrderRule::create([
            'name' => 'Test Exclusion Rule Master',
            'description' => 'Test: Category A excludes Category B (master)',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $this->agreementRole->id,
            'permission_id' => $this->consolidadoPermission->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        OrderRuleExclusion::create([
            'order_rule_id' => $orderRule->id,
            'source_type' => Category::class,
            'source_id' => $catA->id,
            'excluded_type' => Category::class,
            'excluded_id' => $catB->id,
        ]);

        $masterUser = $this->createUser(
            $this->agreementRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CONVCONS.MASTER',
            ['master_user' => true, 'validate_subcategory_rules' => true]
        );

        $delegateUser = $this->createUser(
            $this->agreementRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CONVCONS.DELEGATE',
            ['validate_subcategory_rules' => true]
        );

        $previousOrder = $this->createPreviousOrder($delegateUser, [$productA, $productB]);

        Sanctum::actingAs($masterUser);

        // Step 1: GET previous order for delegate
        $getResponse = $this->getPreviousOrder('2026-02-01', 'TEST.CONVCONS.DELEGATE');
        $getResponse->assertStatus(200);
        $this->assertEquals($previousOrder->id, $getResponse->json('data.id'));

        // Step 2: POST create order for delegate → 422
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01',
            'TEST.CONVCONS.DELEGATE'
        );
        $postResponse->assertStatus(422);
    }

    /**
     * Convenio/Consolidado super master user delegating: bypasses validations → 200
     */
    public function test_convenio_consolidado_super_master_bypasses_validations(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CONV CONS SUPER');
        $menu = $this->createMenu($this->agreementRole, $this->consolidadoPermission);

        [$catA, $cmA] = $this->createCategoryWithMenu('ENSALADAS S', $menu, 1);
        [$catB, $cmB] = $this->createCategoryWithMenu('POSTRES CONS S', $menu, 2);

        $subEntrada = Subcategory::create(['name' => 'ENTRADA S']);
        $catA->subcategories()->attach($subEntrada->id);
        $subPostre = Subcategory::create(['name' => 'POSTRE S']);
        $catB->subcategories()->attach($subPostre->id);

        $productA = $this->createProduct('Ensalada Cesar S', $catA, $priceList);
        $productB = $this->createProduct('Torta Chocolate S', $catB, $priceList);
        $cmA->products()->attach($productA->id);
        $cmB->products()->attach($productB->id);

        $orderRule = OrderRule::create([
            'name' => 'Test Exclusion Rule Super',
            'description' => 'Test: Category A excludes Category B (super)',
            'rule_type' => 'subcategory_exclusion',
            'role_id' => $this->agreementRole->id,
            'permission_id' => $this->consolidadoPermission->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        OrderRuleExclusion::create([
            'order_rule_id' => $orderRule->id,
            'source_type' => Category::class,
            'source_id' => $catA->id,
            'excluded_type' => Category::class,
            'excluded_id' => $catB->id,
        ]);

        [$smPriceList, $smCompany, $smBranch] = $this->createCompanyWithPriceList('SUPER MASTER CO CONS');

        $superMasterUser = $this->createUser(
            $this->agreementRole,
            $this->consolidadoPermission,
            $smCompany,
            $smBranch,
            'TEST.CONVCONS.SUPERMASTER',
            ['master_user' => true, 'super_master_user' => true]
        );

        $delegateUser = $this->createUser(
            $this->agreementRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CONVCONS.DELEGATE.SM',
            ['validate_subcategory_rules' => true]
        );

        $previousOrder = $this->createPreviousOrder($delegateUser, [$productA, $productB]);

        Sanctum::actingAs($superMasterUser);

        // Step 1: GET previous order for delegate
        $getResponse = $this->getPreviousOrder('2026-02-01', 'TEST.CONVCONS.DELEGATE.SM');
        $getResponse->assertStatus(200);

        // Step 2: POST create order → 200 (super master bypasses ALL validations)
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01',
            'TEST.CONVCONS.DELEGATE.SM'
        );
        $postResponse->assertStatus(200);

        // Verify order belongs to delegate
        $createdOrderId = $postResponse->json('data.id');
        $createdOrder = Order::find($createdOrderId);
        $this->assertEquals($delegateUser->id, $createdOrder->user_id);
        $this->assertEquals(2, $createdOrder->orderLines->count());
    }

    // =====================================================================
    // 3. CAFÉ / CONSOLIDADO
    // Validation: DispatchRulesCategoriesValidation
    // Trigger: Product from category with no CategoryLine for dispatch day,
    //          user has allow_late_orders=true
    // =====================================================================

    /**
     * Café/Consolidado regular user: previous order with product whose category
     * has no CategoryLine for dispatch day → DispatchRulesCategoriesValidation → 422
     */
    public function test_cafe_consolidado_regular_user_triggers_validation_error(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CAFE CONS');
        $menu = $this->createMenu($this->cafeRole, $this->consolidadoPermission);

        // Category WITH CategoryLine for sunday (valid product)
        [$catValid, $cmValid] = $this->createCategoryWithMenu('SANDWICHES', $menu, 1);
        $validProduct = $this->createProduct('Sandwich Jamon', $catValid, $priceList);
        $cmValid->products()->attach($validProduct->id);

        // Category WITHOUT CategoryLine for sunday (will trigger validation error)
        $catNoLine = Category::create([
            'name' => 'PLATOS ESPECIALES',
            'description' => 'Category without sunday availability',
            'active' => true,
        ]);
        // Only has CategoryLine for monday, NOT sunday
        CategoryLine::create([
            'category_id' => $catNoLine->id,
            'weekday' => 'monday',
            'preparation_days' => 0,
            'maximum_order_time' => '23:59:00',
            'active' => true,
        ]);
        $cmNoLine = CategoryMenu::create([
            'category_id' => $catNoLine->id,
            'menu_id' => $menu->id,
            'display_order' => 2,
            'show_all_products' => true,
        ]);
        $unavailableProduct = $this->createProduct('Plato Especial', $catNoLine, $priceList);
        $cmNoLine->products()->attach($unavailableProduct->id);

        $user = $this->createUser(
            $this->cafeRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CAFECONS.USER',
            ['allow_late_orders' => true]
        );

        // Previous order has both products (one unavailable for sunday)
        $previousOrder = $this->createPreviousOrder($user, [$validProduct, $unavailableProduct]);

        Sanctum::actingAs($user);

        // Step 1: GET previous order
        $getResponse = $this->getPreviousOrder('2026-02-01');
        $getResponse->assertStatus(200);
        $this->assertEquals($previousOrder->id, $getResponse->json('data.id'));

        // Step 2: POST create order → 422 (product not available for sunday)
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01'
        );
        $postResponse->assertStatus(422);
    }

    /**
     * Café/Consolidado master user delegating: same availability issue → 422
     */
    public function test_cafe_consolidado_master_user_triggers_validation_error(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CAFE CONS MASTER');
        $menu = $this->createMenu($this->cafeRole, $this->consolidadoPermission);

        [$catValid, $cmValid] = $this->createCategoryWithMenu('SANDWICHES M', $menu, 1);
        $validProduct = $this->createProduct('Sandwich Jamon M', $catValid, $priceList);
        $cmValid->products()->attach($validProduct->id);

        $catNoLine = Category::create([
            'name' => 'PLATOS ESPECIALES M',
            'description' => 'Category without sunday availability (master)',
            'active' => true,
        ]);
        CategoryLine::create([
            'category_id' => $catNoLine->id,
            'weekday' => 'monday',
            'preparation_days' => 0,
            'maximum_order_time' => '23:59:00',
            'active' => true,
        ]);
        $cmNoLine = CategoryMenu::create([
            'category_id' => $catNoLine->id,
            'menu_id' => $menu->id,
            'display_order' => 2,
            'show_all_products' => true,
        ]);
        $unavailableProduct = $this->createProduct('Plato Especial M', $catNoLine, $priceList);
        $cmNoLine->products()->attach($unavailableProduct->id);

        $masterUser = $this->createUser(
            $this->cafeRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CAFECONS.MASTER',
            ['master_user' => true, 'allow_late_orders' => true]
        );

        $delegateUser = $this->createUser(
            $this->cafeRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CAFECONS.DELEGATE',
            ['allow_late_orders' => true]
        );

        $previousOrder = $this->createPreviousOrder($delegateUser, [$validProduct, $unavailableProduct]);

        Sanctum::actingAs($masterUser);

        // Step 1: GET previous order for delegate
        $getResponse = $this->getPreviousOrder('2026-02-01', 'TEST.CAFECONS.DELEGATE');
        $getResponse->assertStatus(200);
        $this->assertEquals($previousOrder->id, $getResponse->json('data.id'));

        // Step 2: POST create order for delegate → 422
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01',
            'TEST.CAFECONS.DELEGATE'
        );
        $postResponse->assertStatus(422);
    }

    /**
     * Café/Consolidado super master user delegating: bypasses validations → 200
     */
    public function test_cafe_consolidado_super_master_bypasses_validations(): void
    {
        [$priceList, $company, $branch] = $this->createCompanyWithPriceList('CAFE CONS SUPER');
        $menu = $this->createMenu($this->cafeRole, $this->consolidadoPermission);

        [$catValid, $cmValid] = $this->createCategoryWithMenu('SANDWICHES S', $menu, 1);
        $validProduct = $this->createProduct('Sandwich Jamon S', $catValid, $priceList);
        $cmValid->products()->attach($validProduct->id);

        $catNoLine = Category::create([
            'name' => 'PLATOS ESPECIALES S',
            'description' => 'Category without sunday availability (super)',
            'active' => true,
        ]);
        CategoryLine::create([
            'category_id' => $catNoLine->id,
            'weekday' => 'monday',
            'preparation_days' => 0,
            'maximum_order_time' => '23:59:00',
            'active' => true,
        ]);
        $cmNoLine = CategoryMenu::create([
            'category_id' => $catNoLine->id,
            'menu_id' => $menu->id,
            'display_order' => 2,
            'show_all_products' => true,
        ]);
        $unavailableProduct = $this->createProduct('Plato Especial S', $catNoLine, $priceList);
        $cmNoLine->products()->attach($unavailableProduct->id);

        [$smPriceList, $smCompany, $smBranch] = $this->createCompanyWithPriceList('SUPER MASTER CO CAFE');

        $superMasterUser = $this->createUser(
            $this->cafeRole,
            $this->consolidadoPermission,
            $smCompany,
            $smBranch,
            'TEST.CAFECONS.SUPERMASTER',
            ['master_user' => true, 'super_master_user' => true]
        );

        $delegateUser = $this->createUser(
            $this->cafeRole,
            $this->consolidadoPermission,
            $company,
            $branch,
            'TEST.CAFECONS.DELEGATE.SM',
            ['allow_late_orders' => true]
        );

        $previousOrder = $this->createPreviousOrder($delegateUser, [$validProduct, $unavailableProduct]);

        Sanctum::actingAs($superMasterUser);

        // Step 1: GET previous order for delegate
        $getResponse = $this->getPreviousOrder('2026-02-01', 'TEST.CAFECONS.DELEGATE.SM');
        $getResponse->assertStatus(200);

        // Step 2: POST create order → 200 (super master bypasses ALL validations)
        $postResponse = $this->createOrderFromPreviousLines(
            $getResponse->json('data.order_lines'),
            '2026-02-01',
            'TEST.CAFECONS.DELEGATE.SM'
        );
        $postResponse->assertStatus(200);

        // Verify order belongs to delegate
        $createdOrderId = $postResponse->json('data.id');
        $createdOrder = Order::find($createdOrderId);
        $this->assertEquals($delegateUser->id, $createdOrder->user_id);
        $this->assertEquals(2, $createdOrder->orderLines->count());
    }
}