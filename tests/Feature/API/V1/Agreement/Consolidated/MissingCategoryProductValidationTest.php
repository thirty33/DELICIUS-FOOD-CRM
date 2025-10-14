<?php

namespace Tests\Feature\API\V1\Agreement\Consolidated;

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
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test for AtLeastOneProductByCategory validation with show_all_products = true
 *
 * This test validates the EXPECTED behavior (TDD approach) when:
 * - User is Convenio Consolidado
 * - CategoryMenu has show_all_products = true
 * - NO products are in the pivot table (category_menu_product is empty)
 * - Products exist in the categories with active price list lines
 * - Order is missing products from available categories
 *
 * EXPECTED BEHAVIOR:
 * - When updating order status to PROCESSED
 * - Validation should FAIL if any category with available products is missing
 * - Error: "La orden debe incluir al menos un producto de la categoría: {$category->name}"
 *
 * CURRENT BUG:
 * - getCategoryMenusForValidation only checks products in pivot table
 * - When show_all_products = true, pivot table is empty
 * - Validation incorrectly passes even when categories are missing
 *
 * REAL CASE STRUCTURE (anonymized):
 * - Menu with 13 categories, show_all_products = true
 * - Order missing products from BEBESTIBLES category
 * - Category has products with prices but none selected
 */
class MissingCategoryProductValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that order status update fails when missing products from available categories
     * even when show_all_products = true and pivot table is empty
     *
     * This test documents the EXPECTED behavior (currently failing due to bug)
     */
    public function test_order_status_update_fails_when_missing_category_with_show_all_products_true(): void
    {
        // Set test time to before max_order_date
        Carbon::setTestNow(Carbon::parse('2025-10-15 10:00:00'));

        // === CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $company = Company::create([
            'name' => 'TEST COMPANY CONSOLIDATED',
            'fantasy_name' => 'TEST CO',
            'address' => 'Test Address',
            'email' => 'test@testcompany.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG-TEST-001',
            'description' => 'Test company for consolidated agreements',
            'active' => true,
            'tax_id' => '123456789',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST CONSOLIDATED PRICE LIST',
            'description' => 'Price list for test company',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => 'TEST CONSOLIDATED BRANCH',
            'address' => 'Test Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === CREATE USER (Convenio Consolidado) ===
        $user = User::create([
            'name' => 'TEST CONSOLIDATED USER',
            'nickname' => 'TEST.CONS',
            'email' => 'test.consolidated@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'allow_late_orders' => true,
            'validate_min_price' => false,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($consolidatedPermission->id);

        // === CREATE CATEGORIES ===
        $ciabattasCategory = Category::create([
            'name' => 'CIABATTAS',
            'description' => 'Ciabattas sandwiches',
            'is_active' => true,
        ]);

        $bebestiblesCategory = Category::create([
            'name' => 'BEBESTIBLES',
            'description' => 'Beverages',
            'is_active' => true,
        ]);

        // === CREATE CATEGORY LINES (for dispatch rules) ===
        // Menu date is 2025-10-16 (Thursday), so we need CategoryLines for Thursday
        \App\Models\CategoryLine::create([
            'category_id' => $ciabattasCategory->id,
            'weekday' => \App\Enums\Weekday::THURSDAY->value, // Thursday
            'preparation_days' => 1,
            'maximum_order_time' => '23:59:00',
            'active' => true,
        ]);

        \App\Models\CategoryLine::create([
            'category_id' => $bebestiblesCategory->id,
            'weekday' => \App\Enums\Weekday::THURSDAY->value, // Thursday
            'preparation_days' => 1,
            'maximum_order_time' => '23:59:00',
            'active' => true,
        ]);

        // === CREATE PRODUCTS ===
        // Products for CIABATTAS
        $ciabattaProduct = Product::create([
            'name' => 'SND - SAND. CIABATTA DE CARNE MECHADA',
            'description' => 'Ciabatta sandwich with shredded meat',
            'code' => 'SND-CIA-001',
            'category_id' => $ciabattasCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // Products for BEBESTIBLES
        $waterProduct = Product::create([
            'name' => 'BEB - AGUA MINERAL S/GAS 500 ML',
            'description' => 'Still mineral water 500ml',
            'code' => 'BEB-AGUA-001',
            'category_id' => $bebestiblesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $cokeProduct = Product::create([
            'name' => 'BEB - COCA COLA 350 ML',
            'description' => 'Coca Cola 350ml',
            'code' => 'BEB-COKE-001',
            'category_id' => $bebestiblesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // === CREATE PRICE LIST LINES ===
        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $ciabattaProduct->id,
            'unit_price' => 300000, // $3,000.00
            'active' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $waterProduct->id,
            'unit_price' => 100000, // $1,000.00
            'active' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $cokeProduct->id,
            'unit_price' => 150000, // $1,500.00
            'active' => true,
        ]);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONSOLIDATED AGREEMENT COLD LUNCH 16/10/25',
            'description' => 'Cold lunch menu for consolidated agreement',
            'publication_date' => '2025-10-16',
            'max_order_date' => '2025-10-15 18:00:00',
            'role_id' => $agreementRole->id,
            'permissions_id' => $consolidatedPermission->id,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS ===
        // IMPORTANT: show_all_products = true, NO products in pivot table
        $ciabattasCategoryMenu = CategoryMenu::create([
            'category_id' => $ciabattasCategory->id,
            'menu_id' => $menu->id,
            'display_order' => 10,
            'show_all_products' => true, // KEY: show_all_products is TRUE
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        // NOT adding products to pivot table (simulating real scenario)

        $bebestiblesCategoryMenu = CategoryMenu::create([
            'category_id' => $bebestiblesCategory->id,
            'menu_id' => $menu->id,
            'display_order' => 20,
            'show_all_products' => true, // KEY: show_all_products is TRUE
            'mandatory_category' => true,
            'is_active' => true,
        ]);
        // NOT adding products to pivot table (simulating real scenario)

        // === CREATE ORDER ===
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => '2025-10-16',
            'status' => OrderStatus::PENDING->value,
            'total' => 0,
        ]);

        // === CREATE ORDER LINES ===
        // Only adding product from CIABATTAS, NOT from BEBESTIBLES
        $orderLine = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $ciabattaProduct->id,
            'quantity' => 1,
            'unit_price' => 300000,
        ]);

        // Update order total
        $order->update(['total' => 300000]);

        // === AUTHENTICATE USER ===
        Sanctum::actingAs($user);

        // === ATTEMPT TO UPDATE ORDER STATUS TO PROCESSED ===
        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-16", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // === ASSERTIONS ===
        // EXPECTED: Should return 422 with error about missing BEBESTIBLES
        // With the fix applied, this should now work correctly

        // Debug output to understand what's happening
        if ($response->status() !== 422) {
            dump('Response status:', $response->status());
            dump('Response body:', $response->json());
            $this->fail(
                "Expected status 422 but got {$response->status()}. " .
                    "Response: " . json_encode($response->json())
            );
        }

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'message'
            ]
        ]);

        // Verify the specific error message
        $responseData = $response->json();
        $this->assertEquals('error', $responseData['message']);
        $this->assertStringContainsString(
            'Bebestibles',
            $responseData['errors']['message'][0]
        );

        // Verify order status did not change
        $order->refresh();
        $this->assertEquals(OrderStatus::PENDING->value, $order->status);
    }

    /**
     * Test that validation correctly identifies when show_all_products = true
     * and should check products in category instead of pivot table
     */
    public function test_category_menu_repository_should_handle_show_all_products_correctly(): void
    {
        // Set test time
        Carbon::setTestNow(Carbon::parse('2025-10-15 10:00:00'));

        // === SETUP (similar to above) ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        $company = Company::create([
            'name' => 'TEST COMPANY',
            'fantasy_name' => 'TEST',
            'address' => 'Test Address',
            'email' => 'test@test.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG-TEST-001',
            'description' => 'Test company',
            'active' => true,
            'tax_id' => '123456789',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST PRICE LIST',
            'description' => 'Test price list',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        $branch = Branch::create([
            'name' => 'TEST BRANCH',
            'address' => 'Test Branch',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        $user = User::create([
            'name' => 'TEST USER',
            'nickname' => 'TEST.USER',
            'email' => 'test@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'allow_late_orders' => true,
            'validate_min_price' => false,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($consolidatedPermission->id);

        $category = Category::create([
            'name' => 'TEST CATEGORY',
            'description' => 'Test category',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'TEST PRODUCT',
            'description' => 'Test product',
            'code' => 'TEST-001',
            'category_id' => $category->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
            'unit_price' => 100000,
            'active' => true,
        ]);

        $menu = Menu::create([
            'title' => 'TEST MENU',
            'description' => 'Test menu',
            'publication_date' => '2025-10-16',
            'max_order_date' => '2025-10-15 18:00:00',
            'role_id' => $agreementRole->id,
            'permissions_id' => $consolidatedPermission->id,
            'active' => true,
        ]);

        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 10,
            'show_all_products' => true, // KEY: show_all_products is TRUE
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        // NOT adding products to pivot table

        // === TEST REPOSITORY ===
        $categoryMenuRepository = app(\App\Repositories\CategoryMenuRepository::class);
        $categoryMenusForValidation = $categoryMenuRepository->getCategoryMenusForValidation($menu, $user);

        // EXPECTED: Should return 1 category menu (because category has products with prices)
        // CURRENT BUG: Returns 0 (because pivot table is empty)

        $this->assertCount(
            1,
            $categoryMenusForValidation,
            'Repository should return category menus when show_all_products=true and category has products with prices'
        );

        if ($categoryMenusForValidation->isNotEmpty()) {
            $this->assertEquals(
                'TEST CATEGORY',
                $categoryMenusForValidation->first()->category->name
            );
        }
    }

    /**
     * Test that exactly replicates the real case structure with all 13 categories
     *
     * This test reproduces the exact scenario structure:
     * - Menu with all 13 sandwich categories
     * - Order has only one CIABATTA product, missing BEBESTIBLES
     * - User type: Convenio Consolidado
     * - All categories with show_all_products = true
     *
     * EXPECTED: Error about missing BEBESTIBLES category
     */
    public function test_complete_menu_with_all_thirteen_categories_missing_bebestibles(): void
    {
        // Set test time to before max_order_date
        Carbon::setTestNow(Carbon::parse('2025-10-15 10:00:00'));

        // === CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE TEST COMPANY ===
        $company = Company::create([
            'name' => 'TEST COMPANY FULL MENU',
            'fantasy_name' => 'TEST FULL',
            'address' => 'Test Address 123',
            'email' => 'test@fullmenu.com',
            'phone_number' => '555123456',
            'registration_number' => 'REG-TEST-002',
            'description' => 'Test company with full menu',
            'active' => true,
            'tax_id' => '987654321',
        ]);

        // === CREATE PRICE LIST ===
        $priceList = PriceList::create([
            'name' => 'TEST FULL PRICE LIST',
            'description' => 'Price list for full menu test',
            'min_price_order' => 0,
            'active' => true,
        ]);

        $company->update(['price_list_id' => $priceList->id]);

        // === CREATE BRANCH ===
        $branch = Branch::create([
            'name' => 'TEST FULL BRANCH',
            'address' => 'Test Branch Address 456',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // === CREATE TEST USER (Convenio Consolidado) ===
        $user = User::create([
            'name' => 'TEST COLD LUNCH USER',
            'nickname' => 'TEST.COLD',
            'email' => 'test.cold@fullmenu.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'allow_late_orders' => true,
            'validate_min_price' => false,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($consolidatedPermission->id);

        // === CREATE ALL 13 CATEGORIES ===
        $categories = [];
        $categoryNames = [
            'CIABATTAS',
            'BAGUETTES',
            'BRIOCHE',
            'CROISSANTS',
            'MARRAQUETAS',
            'MIGA EXTRA BLANCO',
            'MIGA EXTRA INTEGRAL',
            'MIGA EXTRA SIMPLE BLANCO',
            'MIGA EXTRA SIMPLE INTEGRAL',
            'MIGA TRIPLE BLANCO',
            'MIGA TRIPLE INTEGRAL',
            'WRAPS',
            'BEBESTIBLES'
        ];

        foreach ($categoryNames as $name) {
            $category = Category::create([
                'name' => $name,
                'description' => $name . ' category',
                'is_active' => true,
            ]);
            $categories[$name] = $category;

            // Create CategoryLine for Thursday (dispatch date is 2025-10-16)
            \App\Models\CategoryLine::create([
                'category_id' => $category->id,
                'weekday' => \App\Enums\Weekday::THURSDAY->value,
                'preparation_days' => 1,
                'maximum_order_time' => '23:59:00',
                'active' => true,
            ]);
        }

        // === CREATE SAMPLE PRODUCTS FOR EACH CATEGORY ===
        $products = [];

        // CIABATTAS - This is the product that will be in the order
        $products['ciabatta'] = Product::create([
            'name' => 'SND - SAND. CIABATTA DE CARNE MECHADA',
            'description' => 'Ciabatta sandwich with shredded meat',
            'code' => 'SND-CIA-001',
            'category_id' => $categories['CIABATTAS']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // BAGUETTES
        $products['baguette'] = Product::create([
            'name' => 'SND - SAND. BAGUETTE DE POLLO CAPRESE',
            'description' => 'Test product',
            'code' => 'SND-BAG-001',
            'category_id' => $categories['BAGUETTES']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // BRIOCHE
        $products['brioche'] = Product::create([
            'name' => 'SND - SAND. BRIOCHE BURGER CHEDDAR',
            'description' => 'Test product',
            'code' => 'SND-BRI-001',
            'category_id' => $categories['BRIOCHE']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // CROISSANTS
        $products['croissant'] = Product::create([
            'name' => 'SND - SAND. CROISSANT HUEVO CIBOULETTE',
            'description' => 'Test product',
            'code' => 'SND-CRO-001',
            'category_id' => $categories['CROISSANTS']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // MARRAQUETAS
        $products['marraqueta'] = Product::create([
            'name' => 'SND - SAND. MARRAQUETA AVE MAYO',
            'description' => 'Test product',
            'code' => 'SND-MAR-001',
            'category_id' => $categories['MARRAQUETAS']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // MIGA EXTRA BLANCO
        $products['miga_extra_blanco'] = Product::create([
            'name' => 'SND - SAND. MIGA EXTRA AVE MAYO',
            'description' => 'Test product',
            'code' => 'SND-MEB-001',
            'category_id' => $categories['MIGA EXTRA BLANCO']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // MIGA EXTRA INTEGRAL
        $products['miga_extra_integral'] = Product::create([
            'name' => 'SND - SAND. MIGA INTEGRAL EXTRA AVE MAYO',
            'description' => 'Test product',
            'code' => 'SND-MEI-001',
            'category_id' => $categories['MIGA EXTRA INTEGRAL']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // MIGA EXTRA SIMPLE BLANCO
        $products['miga_simple_blanco'] = Product::create([
            'name' => 'SND - SAND. MIGA EXTRA SIMPLE AVE MAYO',
            'description' => 'Test product',
            'code' => 'SND-MESB-001',
            'category_id' => $categories['MIGA EXTRA SIMPLE BLANCO']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // MIGA EXTRA SIMPLE INTEGRAL
        $products['miga_simple_integral'] = Product::create([
            'name' => 'SND - SAND. MIGA INTEGRAL EXTRA SIMPLE AVE MAYO',
            'description' => 'Test product',
            'code' => 'SND-MESI-001',
            'category_id' => $categories['MIGA EXTRA SIMPLE INTEGRAL']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // MIGA TRIPLE BLANCO
        $products['miga_triple_blanco'] = Product::create([
            'name' => 'SND - SAND. MIGA TRIPLE AVE MAYO',
            'description' => 'Test product',
            'code' => 'SND-MTB-001',
            'category_id' => $categories['MIGA TRIPLE BLANCO']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // MIGA TRIPLE INTEGRAL
        $products['miga_triple_integral'] = Product::create([
            'name' => 'SND - SAND. MIGA INTEGRAL TRIPLE AVE MAYO',
            'description' => 'Test product',
            'code' => 'SND-MTI-001',
            'category_id' => $categories['MIGA TRIPLE INTEGRAL']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // WRAPS
        $products['wrap'] = Product::create([
            'name' => 'SND - WRAP DE ATUN',
            'description' => 'Test product',
            'code' => 'SND-WRP-001',
            'category_id' => $categories['WRAPS']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // BEBESTIBLES - Multiple products available but NONE selected in order
        $products['agua'] = Product::create([
            'name' => 'BEB - AGUA MINERAL C/GAS 500 ML',
            'description' => 'Test product',
            'code' => 'BEB-AGU-001',
            'category_id' => $categories['BEBESTIBLES']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        $products['coca'] = Product::create([
            'name' => 'BEB - COCA COLA 350 ML',
            'description' => 'Test product',
            'code' => 'BEB-COC-001',
            'category_id' => $categories['BEBESTIBLES']->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        // === CREATE PRICE LIST LINES FOR ALL PRODUCTS ===
        foreach ($products as $product) {
            PriceListLine::create([
                'price_list_id' => $priceList->id,
                'product_id' => $product->id,
                'unit_price' => $product->category->name === 'BEBESTIBLES' ? 45000 : 300000,
                'active' => true,
            ]);
        }

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'CONVENIO CONSOLIDADO COLD LUNCH 16/10/25',
            'description' => 'Cold lunch menu for consolidated agreement',
            'publication_date' => '2025-10-16',
            'max_order_date' => '2025-10-15 18:00:00',
            'role_id' => $agreementRole->id,
            'permissions_id' => $consolidatedPermission->id,
            'active' => true,
        ]);

        // === CREATE CATEGORY MENUS WITH EXACT DISPLAY ORDERS ===
        $displayOrders = [
            'CIABATTAS' => 200,
            'BAGUETTES' => 210,
            'BRIOCHE' => 220,
            'CROISSANTS' => 230,
            'MARRAQUETAS' => 240,
            'MIGA EXTRA BLANCO' => 250,
            'MIGA EXTRA INTEGRAL' => 260,
            'MIGA EXTRA SIMPLE BLANCO' => 270,
            'MIGA EXTRA SIMPLE INTEGRAL' => 280,
            'MIGA TRIPLE BLANCO' => 290,
            'MIGA TRIPLE INTEGRAL' => 300,
            'WRAPS' => 310,
            'BEBESTIBLES' => 340,
        ];

        foreach ($categoryNames as $name) {
            CategoryMenu::create([
                'category_id' => $categories[$name]->id,
                'menu_id' => $menu->id,
                'display_order' => $displayOrders[$name],
                'show_all_products' => true, // ALL categories have show_all_products = true
                'mandatory_category' => true, // ALL categories are mandatory
                'is_active' => true,
            ]);
            // NO products in pivot table (show_all_products = true)
        }

        // === CREATE ORDER ===
        $order = Order::create([
            'user_id' => $user->id,
            'dispatch_date' => '2025-10-16',
            'status' => OrderStatus::PENDING->value,
            'total' => 300000,
        ]);

        // === CREATE ORDER LINE - ONLY CIABATTA (missing BEBESTIBLES) ===
        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $products['ciabatta']->id,
            'quantity' => 1,
            'unit_price' => 300000,
        ]);

        // === AUTHENTICATE USER ===
        Sanctum::actingAs($user);

        // === ATTEMPT TO UPDATE ORDER STATUS TO PROCESSED ===
        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-16", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // === ASSERTIONS ===
        // Should return 422 with error about missing BEBESTIBLES

        $response->assertStatus(422);
        $response->assertJsonStructure([
            'message',
            'errors' => [
                'message'
            ]
        ]);

        // Verify the EXACT error message
        $responseData = $response->json();
        $this->assertEquals('error', $responseData['message']);
        $this->assertStringContainsString(
            'Bebestibles',
            $responseData['errors']['message'][0]
        );

        // Verify order status did not change (should remain PENDING)
        $order->refresh();
        $this->assertEquals(OrderStatus::PENDING->value, $order->status);

        // Additional assertion: verify that order total matches exactly
        $this->assertEquals(300000, $order->total);
    }

    // TODO: Uncomment when null product filtering is implemented
    // /**
    //  * Test that reproduces the EXACT bug scenario from production
    //  *
    //  * Based on production scenario (anonymized)
    //  * User: Convenio Consolidado with validate_subcategory_rules = true
    //  *
    //  * CURRENT BUG:
    //  * - Order has 2 CIABATTA products and 1 BEBESTIBLES product
    //  * - API returns 422 with error: "Cada categoría debe tener la misma cantidad de productos"
    //  *
    //  * EXPECTED BEHAVIOR (TDD):
    //  * - When validate_subcategory_rules = true and subcategories exist
    //  * - Categories WITH subcategories are GROUPED (12 categories with PLATO DE FONDO)
    //  * - Categories WITHOUT subcategories are NOT grouped (BEBESTIBLES)
    //  * - Different quantities per category should be ALLOWED when using subcategory grouping
    //  * - API should return 200 OK
    //  *
    //  * ROOT CAUSE:
    //  * - Lines 132-143 in AtLeastOneProductByCategory validate equal quantities for ALL categories
    //  * - This validation should be SKIPPED when validate_subcategory_rules = true AND subcategories exist
    //  */
    // public function test_order_with_different_quantities_should_pass_when_using_subcategory_grouping(): void
    // {
    //     // Set test time to before max_order_date
    //     Carbon::setTestNow(Carbon::parse('2025-10-15 10:00:00'));
    //
    //     // === CREATE ROLES AND PERMISSIONS ===
    //     $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
    //     $consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);
    //
    //     // === CREATE TEST COMPANY ===
    //     $company = Company::create([
    //         'name' => 'TEST COMPANY SUBCATEGORY',
    //         'fantasy_name' => 'TEST SUB',
    //         'address' => 'Test Address 789',
    //         'email' => 'test@subcategory.com',
    //         'phone_number' => '555789123',
    //         'registration_number' => 'REG-TEST-003',
    //         'description' => 'Test company with subcategory grouping',
    //         'active' => true,
    //         'tax_id' => '123789456',
    //     ]);
    //
    //     // === CREATE PRICE LIST ===
    //     $priceList = PriceList::create([
    //         'name' => 'TEST SUBCATEGORY PRICE LIST',
    //         'description' => 'Price list for subcategory grouping test',
    //         'min_price_order' => 0,
    //         'active' => true,
    //     ]);
    //
    //     $company->update(['price_list_id' => $priceList->id]);
    //
    //     // === CREATE BRANCH ===
    //     $branch = Branch::create([
    //         'name' => 'TEST SUBCATEGORY BRANCH',
    //         'address' => 'Test Branch Address 789',
    //         'company_id' => $company->id,
    //         'min_price_order' => 0,
    //         'active' => true,
    //     ]);
    //
    //     // === CREATE TEST USER (Convenio Consolidado with validate_subcategory_rules) ===
    //     $user = User::create([
    //         'name' => 'TEST COLD LUNCH USER',
    //         'nickname' => 'TEST.SUB',
    //         'email' => 'test.sub@subcategory.com',
    //         'password' => bcrypt('password'),
    //         'company_id' => $company->id,
    //         'branch_id' => $branch->id,
    //         'active' => true,
    //         'allow_late_orders' => true,
    //         'validate_min_price' => false,
    //         'validate_subcategory_rules' => true, // KEY: This enables subcategory grouping
    //     ]);
    //
    //     $user->roles()->attach($agreementRole->id);
    //     $user->permissions()->attach($consolidatedPermission->id);
    //
    //     // === CREATE CATEGORIES ===
    //     // CIABATTAS with PLATO DE FONDO subcategory
    //     $ciabattasCategory = Category::create([
    //         'name' => 'CIABATTAS',
    //         'description' => 'Ciabatta sandwiches',
    //         'is_active' => true,
    //     ]);
    //
    //     // Add PLATO DE FONDO subcategory to CIABATTAS
    //     $platoFondoSubcategory = \App\Models\Subcategory::firstOrCreate(
    //         ['name' => \App\Enums\Subcategory::PLATO_DE_FONDO->value],
    //         ['description' => 'Main dish']
    //     );
    //     $ciabattasCategory->subcategories()->attach($platoFondoSubcategory->id);
    //
    //     // BEBESTIBLES without subcategories
    //     $bebestiblesCategory = Category::create([
    //         'name' => 'BEBESTIBLES',
    //         'description' => 'Beverages',
    //         'is_active' => true,
    //     ]);
    //     // NO subcategories for BEBESTIBLES
    //
    //     // === CREATE CATEGORY LINES ===
    //     \App\Models\CategoryLine::create([
    //         'category_id' => $ciabattasCategory->id,
    //         'weekday' => \App\Enums\Weekday::THURSDAY->value,
    //         'preparation_days' => 1,
    //         'maximum_order_time' => '23:59:00',
    //         'active' => true,
    //     ]);
    //
    //     \App\Models\CategoryLine::create([
    //         'category_id' => $bebestiblesCategory->id,
    //         'weekday' => \App\Enums\Weekday::THURSDAY->value,
    //         'preparation_days' => 1,
    //         'maximum_order_time' => '23:59:00',
    //         'active' => true,
    //     ]);
    //
    //     // === CREATE PRODUCTS ===
    //     $ciabattaProduct = Product::create([
    //         'name' => 'SND - SAND. CIABATTA DE CARNE MECHADA',
    //         'description' => 'Ciabatta sandwich with shredded meat',
    //         'code' => 'SND-CIA-001',
    //         'category_id' => $ciabattasCategory->id,
    //         'active' => true,
    //         'measure_unit' => 'UND',
    //         'weight' => 0,
    //         'allow_sales_without_stock' => true,
    //     ]);
    //
    //     $bebestibleProduct = Product::create([
    //         'name' => 'BEB - SIN BEBIDA',
    //         'description' => 'No beverage',
    //         'code' => 'BEB-NONE-001',
    //         'category_id' => $bebestiblesCategory->id,
    //         'active' => true,
    //         'measure_unit' => 'UND',
    //         'weight' => 0,
    //         'allow_sales_without_stock' => true,
    //         'is_null_product' => true, // KEY: This is a null product
    //     ]);
    //
    //     // === CREATE PRICE LIST LINES ===
    //     PriceListLine::create([
    //         'price_list_id' => $priceList->id,
    //         'product_id' => $ciabattaProduct->id,
    //         'unit_price' => 300000, // $3,000.00
    //         'active' => true,
    //     ]);
    //
    //     PriceListLine::create([
    //         'price_list_id' => $priceList->id,
    //         'product_id' => $bebestibleProduct->id,
    //         'unit_price' => 0, // Free (no beverage)
    //         'active' => true,
    //     ]);
    //
    //     // === CREATE MENU ===
    //     $menu = Menu::create([
    //         'title' => 'CONVENIO CONSOLIDADO COLD LUNCH 16/10/25',
    //         'description' => 'Cold lunch menu for consolidated agreement',
    //         'publication_date' => '2025-10-16',
    //         'max_order_date' => '2025-10-15 18:00:00',
    //         'role_id' => $agreementRole->id,
    //         'permissions_id' => $consolidatedPermission->id,
    //         'active' => true,
    //     ]);
    //
    //     // === CREATE CATEGORY MENUS ===
    //     CategoryMenu::create([
    //         'category_id' => $ciabattasCategory->id,
    //         'menu_id' => $menu->id,
    //         'display_order' => 200,
    //         'show_all_products' => true,
    //         'mandatory_category' => true,
    //         'is_active' => true,
    //     ]);
    //
    //     CategoryMenu::create([
    //         'category_id' => $bebestiblesCategory->id,
    //         'menu_id' => $menu->id,
    //         'display_order' => 340,
    //         'show_all_products' => true,
    //         'mandatory_category' => true,
    //         'is_active' => true,
    //     ]);
    //
    //     // === CREATE ORDER ===
    //     $order = Order::create([
    //         'user_id' => $user->id,
    //         'dispatch_date' => '2025-10-16',
    //         'status' => OrderStatus::PENDING->value,
    //         'total' => 600000, // 2 x $3,000.00 + 1 x $0
    //     ]);
    //
    //     // === CREATE ORDER LINES ===
    //     // CIABATTAS: 2 products (quantity = 2)
    //     OrderLine::create([
    //         'order_id' => $order->id,
    //         'product_id' => $ciabattaProduct->id,
    //         'quantity' => 2, // KEY: Different quantity than BEBESTIBLES
    //         'unit_price' => 300000,
    //     ]);
    //
    //     // BEBESTIBLES: 1 product (quantity = 1)
    //     OrderLine::create([
    //         'order_id' => $order->id,
    //         'product_id' => $bebestibleProduct->id,
    //         'quantity' => 1, // KEY: Different quantity than CIABATTAS
    //         'unit_price' => 0,
    //     ]);
    //
    //     // === AUTHENTICATE USER ===
    //     Sanctum::actingAs($user);
    //
    //     // === ATTEMPT TO UPDATE ORDER STATUS TO PROCESSED ===
    //     $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-16", [
    //         'status' => OrderStatus::PROCESSED->value,
    //     ]);
    //
    //     // === ASSERTIONS ===
    //     // EXPECTED: Should return 200 OK (different quantities are allowed with subcategory grouping)
    //     // CURRENT BUG: Returns 422 with "Cada categoría debe tener la misma cantidad de productos"
    //
    //     // Debug output if it fails
    //     if ($response->status() !== 200) {
    //         dump('Response status:', $response->status());
    //         dump('Response body:', $response->json());
    //     }
    //
    //     $response->assertStatus(200);
    //     $response->assertJsonStructure([
    //         'message',
    //         'data'
    //     ]);
    //
    //     // Verify order status changed to PROCESSED
    //     $order->refresh();
    //     $this->assertEquals(OrderStatus::PROCESSED->value, $order->status);
    // }
}
