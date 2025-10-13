<?php

namespace Tests\Feature\API\V1;

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
use App\Models\CategoryLine;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test for Order Creation when Menu max_order_date has expired
 *
 * This test validates the behavior when a user with allow_late_orders = true
 * tries to create an order after the menu's max_order_date has passed.
 *
 * SCENARIO:
 * - User type: Café Consolidado
 * - Menu publication_date: 2025-10-13
 * - Menu max_order_date: 2025-10-10 15:30:00
 * - Order attempt date: 2025-10-13 (after max_order_date)
 * - User has allow_late_orders: true
 *
 * EXPECTED BEHAVIOR:
 * - API should return error: "No hay un menú disponible para esta fecha de despacho"
 * - Status code: 422 (Unprocessable Entity)
 * - Reason: MenuExistsValidation fails because max_order_date has passed
 *
 * VALIDATION CHAIN:
 * 1. MenuExistsValidation checks getCurrentMenuQuery()
 * 2. getCurrentMenuQuery() filters by max_order_date > Carbon::now() when allow_late_orders = true
 * 3. Since current date (2025-10-13) > max_order_date (2025-10-10 15:30:00), menu is not found
 * 4. MenuExistsValidation throws exception: "No hay un menú disponible para esta fecha de despacho"
 */
class OrderMenuExpiredMaxOrderDateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that order creation fails when max_order_date has expired
     * even though user has allow_late_orders enabled
     */
    public function test_order_creation_fails_when_max_order_date_expired(): void
    {
        // Set the current time to 2025-10-13 22:00:00 (after max_order_date)
        Carbon::setTestNow(Carbon::parse('2025-10-13 22:00:00'));

        // === CREATE ROLES AND PERMISSIONS ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY',
            'fantasy_name' => 'TEST CONSOLIDATED',
            'address' => 'Test Address 123',
            'email' => 'test@consolidated.com',
            'phone_number' => '987654321',
            'registration_number' => 'REG-TEST-001',
            'description' => 'Test company for consolidated cafe',
            'active' => true,
            'tax_id' => '987654321',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST CAFE PRICE LIST',
            'description' => 'Test price list for cafe',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => 'TEST CONSOLIDATED BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === CREATE USER (Café Consolidado) ===
        $user = User::create([
            'name' => 'TEST CONSOLIDATED USER',
            'nickname' => 'TEST.CONSOLIDATED',
            'email' => 'testconsolidated@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => false,
            'allow_late_orders' => true, // KEY: User can order late, but menu max_order_date still applies
            'validate_min_price' => false,
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST CAFETERIA CONSOLIDADO MENU',
            'description' => null,
            'publication_date' => '2025-10-13',
            'max_order_date' => '2025-10-10 15:30:00', // KEY: max_order_date has already passed
            'role_id' => $cafeRole->id,
            'permissions_id' => $consolidadoPermission->id,
            'active' => true,
        ]);

        // Menu is NOT company-specific (no companies attached)
        // This makes it a general menu available to all companies with matching role/permission

        // === CREATE CATEGORY WITH PRODUCT ===
        $category = Category::create([
            'name' => 'TEST HEATED DISHES',
            'description' => 'Test category for heated dishes',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'TEST - BEEF DISH WITH RICE',
            'code' => 'TEST-PROD-001',
            'description' => 'Test product',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENU ===
        CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 100,
            'show_all_products' => true,
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // === TEST: Make API request to create order ===
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

        $response = $this->postJson('/api/v1/orders/create-or-update-order/2025-10-13', $payload);

        // === ASSERTIONS ===
        // EXPECTED BEHAVIOR:
        // - Should return 422 Unprocessable Entity
        // - Should return error message: "No hay un menú disponible para esta fecha de despacho"

        $response->assertStatus(422);

        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    'No hay un menú disponible para esta fecha de despacho',
                ],
            ],
        ]);

        // Verify that no order was created
        $this->assertDatabaseMissing('orders', [
            'user_id' => $user->id,
        ]);

        // === EXPLANATION ===
        // Why this happens:
        // 1. User makes POST request to create order for 2025-10-13
        // 2. OrderController::update() is called
        // 3. MenuExistsValidation::check() is executed (line 186 in OrderController)
        // 4. MenuExistsValidation calls MenuHelper::getCurrentMenuQuery($date, $user)
        // 5. getCurrentMenuQuery() applies filter: ->where('max_order_date', '>', Carbon::now())
        //    (line 58 in MenuHelper.php, because allow_late_orders = true)
        // 6. Current time: 2025-10-13 22:00:00
        //    Menu max_order_date: 2025-10-10 15:30:00
        //    Result: max_order_date (2025-10-10) is NOT > current time (2025-10-13)
        // 7. Query returns no menu
        // 8. MenuExistsValidation throws exception
        // 9. Exception is caught in OrderController::update() catch block (line 254)
        // 10. ApiResponseService::unprocessableEntity() returns 422 with error message

        // === RELEVANT CODE LOCATIONS ===
        // - MenuExistsValidation: app/Classes/Orders/Validations/MenuExistsValidation.php:15-27
        // - MenuHelper::getCurrentMenuQuery(): app/Classes/Menus/MenuHelper.php:42-82
        // - OrderController::update(): app/Http/Controllers/API/V1/OrderController.php:152-259

        // Reset Carbon time after test
        Carbon::setTestNow();
    }

    /**
     * Test that order creation SUCCEEDS when max_order_date has NOT expired
     *
     * This complementary test validates the normal flow when ordering within the allowed time
     */
    public function test_order_creation_succeeds_when_max_order_date_not_expired(): void
    {
        // Set the current time to BEFORE max_order_date
        Carbon::setTestNow(Carbon::parse('2025-10-09 10:00:00'));

        // === CREATE ROLES AND PERMISSIONS ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY',
            'fantasy_name' => 'TEST CONSOLIDATED',
            'address' => 'Test Address 123',
            'email' => 'test@consolidated.com',
            'phone_number' => '987654321',
            'registration_number' => 'REG-TEST-001',
            'description' => 'Test company for consolidated cafe',
            'active' => true,
            'tax_id' => '987654321',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST CAFE PRICE LIST',
            'description' => 'Test price list for cafe',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => 'TEST CONSOLIDATED BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === CREATE USER ===
        $user = User::create([
            'name' => 'TEST CONSOLIDATED USER',
            'nickname' => 'TEST.CONSOLIDATED',
            'email' => 'testconsolidated@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => false,
            'allow_late_orders' => true,
            'validate_min_price' => false,
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST CAFETERIA CONSOLIDADO MENU',
            'description' => null,
            'publication_date' => '2025-10-13',
            'max_order_date' => '2025-10-10 15:30:00', // Current time is BEFORE this
            'role_id' => $cafeRole->id,
            'permissions_id' => $consolidadoPermission->id,
            'active' => true,
        ]);

        // === CREATE CATEGORY WITH PRODUCT ===
        $category = Category::create([
            'name' => 'TEST HEATED DISHES',
            'description' => 'Test category for heated dishes',
            'is_active' => true,
        ]);

        // Create category lines for all weekdays to make product available
        $weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($weekdays as $weekday) {
            CategoryLine::create([
                'category_id' => $category->id,
                'weekday' => $weekday,
                'preparation_days' => 1,
                'maximum_order_time' => '15:00:00',
                'active' => true,
            ]);
        }

        $product = Product::create([
            'name' => 'TEST - BEEF DISH WITH RICE',
            'code' => 'TEST-PROD-001',
            'description' => 'Test product',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENU ===
        CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 100,
            'show_all_products' => true,
            'is_active' => true,
            'mandatory_category' => false,
        ]);

        // === TEST: Make API request to create order ===
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

        $response = $this->postJson('/api/v1/orders/create-or-update-order/2025-10-13', $payload);

        // === ASSERTIONS ===
        // Should succeed because current time (2025-10-09 10:00:00) < max_order_date (2025-10-10 15:30:00)
        $response->assertStatus(200);

        $response->assertJson([
            'message' => 'Order updated successfully',
        ]);

        // Verify that order was created
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
        ]);

        // Reset Carbon time after test
        Carbon::setTestNow();
    }
}
