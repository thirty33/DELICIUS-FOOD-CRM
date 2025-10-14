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
 * Test for Order Minimum Price Validation
 *
 * This test validates the behavior when a user tries to process an order
 * that does not meet the minimum price requirement set in the branch.
 *
 * SCENARIO:
 * - User type: Café Consolidado with validate_min_price = true
 * - Branch minimum price: $70,000.00
 * - Order total: $2,950.00 (below minimum)
 * - User attempts to change order status to PROCESSED
 *
 * EXPECTED BEHAVIOR:
 * - API should return error: "El monto del pedido mínimo es $70.000,00"
 * - Status code: 422 (Unprocessable Entity)
 * - Reason: MaxOrderAmountValidation fails because order total < branch min_price_order
 *
 * VALIDATION CHAIN (in updateOrderStatus):
 * 1. MenuExistsValidation - checks menu availability
 * 2. DispatchRulesCategoriesValidation - validates product availability
 * 3. AtLeastOneProductByCategory - validates product requirements
 * 4. MaxOrderAmountValidation - validates minimum order amount (THIS ONE FAILS)
 * 5. SubcategoryExclusion - validates subcategory rules
 * 6. MenuCompositionValidation - validates menu composition
 * 7. MandatoryCategoryValidation - validates mandatory categories
 */
class OrderMinimumPriceValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that order status update fails when order total is below branch minimum price
     */
    public function test_order_status_update_fails_when_below_minimum_price(): void
    {
        // Set test time to before max_order_date
        Carbon::setTestNow(Carbon::parse('2025-10-17 10:00:00'));

        // === CREATE ROLES AND PERMISSIONS ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $company = Company::create([
            'name' => 'TEST MINIMUM PRICE COMPANY',
            'fantasy_name' => 'TEST MIN PRICE',
            'address' => 'Test Address 789',
            'email' => 'test@minprice.com',
            'phone_number' => '555123456',
            'registration_number' => 'REG-MIN-001',
            'description' => 'Test company for minimum price validation',
            'active' => true,
            'tax_id' => '555123456',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST MINIMUM PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        // === CREATE BRANCH WITH MINIMUM PRICE ===
        $branch = Branch::create([
            'name' => 'TEST MINIMUM PRICE BRANCH',
            'address' => 'Test Branch Address 999',
            'company_id' => $company->id,
            'min_price_order' => 7000000, // $70,000.00 (in cents)
            'active' => true,
        ]);

        // === CREATE USER WITH PRICE VALIDATION ENABLED ===
        $user = User::create([
            'name' => 'TEST MINIMUM PRICE USER',
            'nickname' => 'TEST.MINPRICE',
            'email' => 'testminprice@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => false,
            'allow_late_orders' => true,
            'validate_min_price' => true, // KEY: Minimum price validation is enabled
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST CAFETERIA MENU WITH MIN PRICE',
            'description' => null,
            'publication_date' => '2025-10-19',
            'max_order_date' => '2025-10-17 15:30:00',
            'role_id' => $cafeRole->id,
            'permissions_id' => $consolidadoPermission->id,
            'active' => true,
        ]);

        // === CREATE CATEGORY WITH PRODUCT ===
        $category = Category::create([
            'name' => 'TEST CAFE DISHES',
            'description' => 'Test category for cafe',
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
            'name' => 'TEST - PORK DISH WITH RICE',
            'code' => 'TEST-PORK-001',
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
            'unit_price' => 295000, // $2,950.00 (in cents) - BELOW minimum
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

        // === CREATE ORDER WITH ORDER LINE ===
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => '2025-10-19',
            'status' => OrderStatus::PENDING->value,
            'total' => 295000, // $2,950.00 (in cents)
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 295000,
            'partially_scheduled' => false,
        ]);

        // === TEST: Attempt to update order status to PROCESSED ===
        Sanctum::actingAs($user);

        $payload = [
            'status' => 'PROCESSED',
        ];

        $response = $this->postJson('/api/v1/orders/update-order-status/2025-10-19', $payload);

        // === ASSERTIONS ===
        // EXPECTED BEHAVIOR:
        // - Should return 422 Unprocessable Entity
        // - Should return error message about minimum price

        $response->assertStatus(422);

        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    'El monto del pedido mínimo es $70.000,00',
                ],
            ],
        ]);

        // Verify that order status was NOT updated
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PENDING->value, // Still PENDING, not PROCESSED
        ]);

        // === EXPLANATION ===
        // Why this happens:
        // 1. User makes POST request to update order status to PROCESSED
        // 2. OrderController::updateOrderStatus() is called (line 318)
        // 3. Validation chain is executed (line 343-353):
        //    a. MenuExistsValidation - passes
        //    b. DispatchRulesCategoriesValidation - passes
        //    c. AtLeastOneProductByCategory - passes
        //    d. MaxOrderAmountValidation - FAILS HERE
        // 4. MaxOrderAmountValidation::check() (line 13 in MaxOrderAmountValidation.php):
        //    - Checks if user->validate_min_price is true (line 15) ✓
        //    - Compares user->branch->min_price_order (7000000) > order->total (295000) ✓
        //    - Since 7000000 > 295000, throws exception with formatted price message
        // 5. Exception is caught in OrderController catch block (line 366)
        // 6. ApiResponseService::unprocessableEntity() returns 422 with error message
        // 7. Order status remains PENDING

        // === RELEVANT CODE LOCATIONS ===
        // - MaxOrderAmountValidation: app/Classes/Orders/Validations/MaxOrderAmountValidation.php:11-24
        // - OrderController::updateOrderStatus(): app/Http/Controllers/API/V1/OrderController.php:318-371
        // - PriceFormatter: app/Classes/PriceFormatter.php

        // Reset Carbon time after test
        Carbon::setTestNow();
    }

    /**
     * Test that order status update SUCCEEDS when order total meets branch minimum price
     */
    public function test_order_status_update_succeeds_when_meets_minimum_price(): void
    {
        // Set test time to before max_order_date
        Carbon::setTestNow(Carbon::parse('2025-10-17 10:00:00'));

        // === CREATE ROLES AND PERMISSIONS ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $company = Company::create([
            'name' => 'TEST MINIMUM PRICE COMPANY',
            'fantasy_name' => 'TEST MIN PRICE',
            'address' => 'Test Address 789',
            'email' => 'test@minprice.com',
            'phone_number' => '555123456',
            'registration_number' => 'REG-MIN-001',
            'description' => 'Test company for minimum price validation',
            'active' => true,
            'tax_id' => '555123456',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST MINIMUM PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        // === CREATE BRANCH WITH MINIMUM PRICE ===
        $branch = Branch::create([
            'name' => 'TEST MINIMUM PRICE BRANCH',
            'address' => 'Test Branch Address 999',
            'company_id' => $company->id,
            'min_price_order' => 7000000, // $70,000.00 (in cents)
            'active' => true,
        ]);

        // === CREATE USER WITH PRICE VALIDATION ENABLED ===
        $user = User::create([
            'name' => 'TEST MINIMUM PRICE USER',
            'nickname' => 'TEST.MINPRICE',
            'email' => 'testminprice@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => false,
            'allow_late_orders' => true,
            'validate_min_price' => true,
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST CAFETERIA MENU WITH MIN PRICE',
            'description' => null,
            'publication_date' => '2025-10-19',
            'max_order_date' => '2025-10-17 15:30:00',
            'role_id' => $cafeRole->id,
            'permissions_id' => $consolidadoPermission->id,
            'active' => true,
        ]);

        // === CREATE CATEGORY WITH PRODUCT ===
        $category = Category::create([
            'name' => 'TEST CAFE DISHES',
            'description' => 'Test category for cafe',
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
            'name' => 'TEST - EXPENSIVE DISH',
            'code' => 'TEST-EXP-001',
            'description' => 'Test product with high price',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 8000000, // $80,000.00 (in cents) - ABOVE minimum
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

        // === CREATE ORDER WITH ORDER LINE ===
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => '2025-10-19',
            'status' => OrderStatus::PENDING->value,
            'total' => 8000000, // $80,000.00 (in cents) - ABOVE minimum
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 8000000,
            'partially_scheduled' => false,
        ]);

        // === TEST: Attempt to update order status to PROCESSED ===
        Sanctum::actingAs($user);

        $payload = [
            'status' => 'PROCESSED',
        ];

        $response = $this->postJson('/api/v1/orders/update-order-status/2025-10-19', $payload);

        // === ASSERTIONS ===
        // Should succeed because order total ($80,000) >= minimum price ($70,000)
        $response->assertStatus(200);

        $response->assertJson([
            'message' => 'Order status updated successfully',
        ]);

        // Verify that order status WAS updated
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // Reset Carbon time after test
        Carbon::setTestNow();
    }

    /**
     * Test that minimum price validation is SKIPPED when validate_min_price is false
     */
    public function test_order_status_update_succeeds_when_validation_disabled(): void
    {
        // Set test time to before max_order_date
        Carbon::setTestNow(Carbon::parse('2025-10-17 10:00:00'));

        // === CREATE ROLES AND PERMISSIONS ===
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);
        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $company = Company::create([
            'name' => 'TEST MINIMUM PRICE COMPANY',
            'fantasy_name' => 'TEST MIN PRICE',
            'address' => 'Test Address 789',
            'email' => 'test@minprice.com',
            'phone_number' => '555123456',
            'registration_number' => 'REG-MIN-001',
            'description' => 'Test company for minimum price validation',
            'active' => true,
            'tax_id' => '555123456',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST MINIMUM PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        // === CREATE BRANCH WITH MINIMUM PRICE ===
        $branch = Branch::create([
            'name' => 'TEST MINIMUM PRICE BRANCH',
            'address' => 'Test Branch Address 999',
            'company_id' => $company->id,
            'min_price_order' => 7000000, // $70,000.00 (in cents)
            'active' => true,
        ]);

        // === CREATE USER WITH PRICE VALIDATION DISABLED ===
        $user = User::create([
            'name' => 'TEST MINIMUM PRICE USER',
            'nickname' => 'TEST.MINPRICE',
            'email' => 'testminprice@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => false,
            'allow_late_orders' => true,
            'validate_min_price' => false, // KEY: Validation is DISABLED
        ]);

        $user->roles()->attach($cafeRole->id);
        $user->permissions()->attach($consolidadoPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST CAFETERIA MENU WITH MIN PRICE',
            'description' => null,
            'publication_date' => '2025-10-19',
            'max_order_date' => '2025-10-17 15:30:00',
            'role_id' => $cafeRole->id,
            'permissions_id' => $consolidadoPermission->id,
            'active' => true,
        ]);

        // === CREATE CATEGORY WITH PRODUCT ===
        $category = Category::create([
            'name' => 'TEST CAFE DISHES',
            'description' => 'Test category for cafe',
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
            'name' => 'TEST - CHEAP DISH',
            'code' => 'TEST-CHEAP-001',
            'description' => 'Test product with low price',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 100000, // $1,000.00 (in cents) - FAR BELOW minimum
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

        // === CREATE ORDER WITH ORDER LINE ===
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => '2025-10-19',
            'status' => OrderStatus::PENDING->value,
            'total' => 100000, // $1,000.00 (in cents) - BELOW minimum but validation disabled
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 100000,
            'partially_scheduled' => false,
        ]);

        // === TEST: Attempt to update order status to PROCESSED ===
        Sanctum::actingAs($user);

        $payload = [
            'status' => 'PROCESSED',
        ];

        $response = $this->postJson('/api/v1/orders/update-order-status/2025-10-19', $payload);

        // === ASSERTIONS ===
        // Should succeed because validate_min_price = false (validation skipped)
        $response->assertStatus(200);

        $response->assertJson([
            'message' => 'Order status updated successfully',
        ]);

        // Verify that order status WAS updated
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // Reset Carbon time after test
        Carbon::setTestNow();
    }
}
