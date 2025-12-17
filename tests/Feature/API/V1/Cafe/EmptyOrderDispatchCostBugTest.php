<?php

namespace Tests\Feature\API\V1\Cafe;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryGroup;
use App\Models\CategoryLine;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\DispatchRule;
use App\Models\DispatchRuleRange;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Production Bug Replica Test - Empty Order Shows Dispatch Cost
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.CAFE.USER (production: ALMA.TIERRA, ID: 16)
 * - Company: TEST CAFE COMPANY S.A. (production: LUIS EDUARDO MARTINEZ CARDENAS, ID: 489)
 * - Branch: TEST CAFE BRANCH (production ID: 24)
 * - Order: production order ID 193 (0 order_lines but dispatch_cost: 800000)
 * - Dispatch Rule: DESPACHO MINIMO 70.000 (ID: 6)
 *   - Range: $0 - $70.000 => Cost: $8.000
 *   - Range: $70.001+ => Cost: $0
 *
 * ROOT CAUSE:
 * The dispatch rule is configured to charge $8.000 for orders with total between $0-$70K.
 * When an order has 0 products (total = $0), it matches this range and gets charged $8.000.
 *
 * EXPECTED BEHAVIOR:
 * An empty order (0 products, 0 order_lines) should ALWAYS have dispatch_cost = $0,
 * regardless of what the dispatch rule says, because there's nothing to dispatch.
 *
 * ACTUAL BUG:
 * When all products are deleted from an order, the dispatch_cost remains at $8.000
 * because the rule matches total=$0 with the first range ($0-$70K => $8.000).
 *
 * API ENDPOINT: GET /api/v1/orders/get-orders?page=1
 */
class EmptyOrderDispatchCostBugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-10-25 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_order_must_have_zero_dispatch_cost(): void
    {
        // 1. CREATE ROLES AND PERMISSIONS
        $role = Role::create(['name' => RoleName::CAFE->value]);
        $permission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // 2. CREATE PRICE LIST
        $priceList = PriceList::create([
            'name' => 'TEST CAFE PRICE LIST',
            'is_global' => false,
            'min_price_order' => 0,
        ]);

        // 3. CREATE COMPANY AND BRANCH
        $company = Company::create([
            'name' => 'TEST CAFE COMPANY S.A.',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCAFE001',
            'fantasy_name' => 'Test Cafe Company',
            'address' => 'Test Address 123',
            'email' => 'test.company@test.com',
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'company_id' => $company->id,
            'address' => 'Branch Address 456',
            'min_price_order' => 0,
        ]);

        // 4. CREATE DISPATCH RULE - THIS IS THE KEY PART
        // Rule charges $8.000 for orders $0-$70K (this causes the bug)
        $dispatchRule = DispatchRule::create([
            'name' => 'TEST DISPATCH MIN 70K',
            'active' => true,
            'priority' => 1,
            'all_companies' => false,
            'all_branches' => false,
        ]);

        $dispatchRule->companies()->attach($company->id);
        $dispatchRule->branches()->attach($branch->id);

        DispatchRuleRange::create([
            'dispatch_rule_id' => $dispatchRule->id,
            'min_amount' => 0,
            'max_amount' => 7000000, // $70.000
            'dispatch_cost' => 800000, // $8.000 - THIS WILL BE CHARGED FOR TOTAL=$0
        ]);

        DispatchRuleRange::create([
            'dispatch_rule_id' => $dispatchRule->id,
            'min_amount' => 7000001,
            'max_amount' => null,
            'dispatch_cost' => 0,
        ]);

        // 5. CREATE USER
        $user = User::create([
            'name' => 'Test Cafe User',
            'nickname' => 'TEST.CAFE.USER',
            'email' => 'test.cafe@test.com',
            'password' => Hash::make('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'validate_subcategory_rules' => false,
        ]);

        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        // 6. CREATE MENU
        $menu = Menu::create([
            'title' => 'TEST CAFETERIA CONSOLIDADO',
            'description' => 'Test menu for cafe',
            'publication_date' => '2025-10-25',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 7. CREATE CATEGORY WITH SUBCATEGORIES
        $categoryGroup = CategoryGroup::create(['name' => 'entradas']);
        $subcategoryEntrada = Subcategory::create(['name' => 'ENTRADA']);

        $category = Category::create([
            'name' => 'TEST ENSALADAS',
            'description' => 'Test salads category',
            'category_group_id' => $categoryGroup->id,
        ]);

        $category->subcategories()->attach($subcategoryEntrada->id);

        CategoryLine::create([
            'category_id' => $category->id,
            'weekday' => 'saturday',
            'preparation_days' => 0,
            'maximum_order_time' => '15:00:00',
            'active' => true,
        ]);

        // 8. CREATE PRODUCT
        $product = Product::create([
            'name' => 'Test Mini Salad',
            'description' => 'Test mini salad product',
            'code' => 'TESTMSAL001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 450000, // $4.500
        ]);

        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'order' => 1,
            'show_all_products' => true,
        ]);

        $categoryMenu->products()->attach($product->id);

        // 9. AUTHENTICATE USER
        Sanctum::actingAs($user);

        // 10. CREATE ORDER WITH PRODUCT (will calculate dispatch cost)
        $date = '2025-10-25';
        $response = $this->postJson("/api/v1/orders/create-or-update-order/{$date}", [
            'order_lines' => [
                [
                    'id' => $product->id,
                    'quantity' => 1,
                    'partially_scheduled' => false,
                ],
            ],
        ]);

        $response->assertStatus(200);

        // 11. DELETE ALL PRODUCTS FROM ORDER (order becomes empty)
        $response = $this->deleteJson("/api/v1/orders/delete-order-items/{$date}", [
            'order_lines' => [
                [
                    'id' => $product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertStatus(200);

        // 12. GET ORDER LIST
        $response = $this->getJson('/api/v1/orders/get-orders?page=1');
        $response->assertStatus(200);

        // 13. VERIFY EMPTY ORDER HAS ZERO COSTS
        $orders = $response->json('data.data');
        $this->assertNotEmpty($orders, 'Should have at least one order');

        $emptyOrder = $orders[0];

        // BUG: These assertions will FAIL because dispatch_cost is $8.000
        // Expected: Empty order (0 products) should have ALL costs at $0
        // Actual: dispatch_cost = $8.000 because rule charges for total=$0
        $this->assertEquals('$0', $emptyOrder['total'],
            'Empty order total should be $0');
        $this->assertEquals('$0', $emptyOrder['total_with_tax'],
            'Empty order total_with_tax should be $0');
        $this->assertEquals('$0', $emptyOrder['dispatch_cost'],
            'Empty order (0 products) MUST have dispatch_cost = $0');
        $this->assertEquals('$0', $emptyOrder['tax_amount'],
            'Empty order tax_amount should be $0');
    }
}