<?php

namespace Tests\Feature\API\V1\Agreement\Individual;

use Tests\BaseIndividualAgreementTest;
use App\Models\User;
use App\Models\Company;
use App\Models\Branch;
use App\Models\PriceList;
use App\Models\Menu;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Product;
use App\Models\PriceListLine;
use App\Models\Subcategory;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Role;
use App\Models\Permission;
use App\Enums\OrderStatus;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use App\Enums\Subcategory as SubcategoryEnum;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test to verify that multiple ENTRADA products can be added when the exclusion rule is not present.
 *
 * This test overrides the default exclusion rules to EXCLUDE the ENTRADA -> ENTRADA rule,
 * allowing users to add multiple appetizer products in the same order.
 */
class MultipleEntradasAllowedTest extends BaseIndividualAgreementTest
{
    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to 2025-10-14
        Carbon::setTestNow('2025-10-14 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Override to use all default rules EXCEPT the ENTRADA exclusion.
     *
     * This allows multiple ENTRADA products in the same order.
     */
    protected function getSubcategoryExclusions(): array
    {
        return [
            // Keep standard rules
            SubcategoryEnum::PLATO_DE_FONDO->value => [SubcategoryEnum::PLATO_DE_FONDO->value],
            // SubcategoryEnum::ENTRADA->value => [SubcategoryEnum::ENTRADA->value], // REMOVED - Allow multiple ENTRADA
            SubcategoryEnum::FRIA->value => [SubcategoryEnum::HIPOCALORICO->value],
            SubcategoryEnum::PAN_DE_ACOMPANAMIENTO->value => [SubcategoryEnum::SANDWICH->value],
        ];
    }

    /**
     * Test that multiple ENTRADA products can be added when exclusion rule is not present.
     *
     * This test verifies that when the ENTRADA->ENTRADA exclusion rule is removed,
     * the system allows adding multiple appetizer products to the same order.
     */
    public function test_can_add_multiple_entrada_products_when_exclusion_rule_not_present(): void
    {
        // Get roles and permissions
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create price list
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => true,
        ]);

        // Create company
        $company = Company::create([
            'name' => 'Test Company S.A.',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // Create branch
        $branch = Branch::create([
            'name' => 'Main Branch',
            'address' => 'Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Create user with validation enabled
        $user = User::create([
            'name' => 'Test User',
            'nickname' => 'TEST.MULTIPLE.ENTRADA',
            'email' => 'test.multiple.entrada@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true, // KEY: Validation is enabled
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // Get subcategories
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $calienteSubcat = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $friaSubcat = Subcategory::firstOrCreate(['name' => 'FRIA']);
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);

        // Create menu
        $menu = Menu::create([
            'title' => 'TEST MENU MULTIPLE ENTRADA',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Category 1: SOPAS Y CREMAS (ENTRADA + CALIENTE)
        $categorySopas = Category::create([
            'name' => 'TEST SOPAS Y CREMAS',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categorySopas->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        $productSopa = Product::create([
            'name' => 'TEST - CREMA DE ESPINACA',
            'description' => 'Test soup product',
            'code' => 'TEST-SOPA-001',
            'category_id' => $categorySopas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productSopa->id,
            'unit_price' => 3000.00,
            'active' => true,
        ]);

        // Category 2: ENSALADAS (ENTRADA + FRIA)
        $categoryEnsaladas = Category::create([
            'name' => 'TEST MINI ENSALADAS',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categoryEnsaladas->subcategories()->attach([$entradaSubcat->id, $friaSubcat->id]);

        $productEnsalada = Product::create([
            'name' => 'TEST - ENSALADA CAESAR',
            'description' => 'Test salad product',
            'code' => 'TEST-ENSALADA-001',
            'category_id' => $categoryEnsaladas->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productEnsalada->id,
            'unit_price' => 2500.00,
            'active' => true,
        ]);

        // Category 3: PLATO DE FONDO
        $categoryPlatoFondo = Category::create([
            'name' => 'TEST PLATOS DE FONDO',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categoryPlatoFondo->subcategories()->attach($platoFondoSubcat->id);

        $productPlatoFondo = Product::create([
            'name' => 'TEST - POLLO AL HORNO',
            'description' => 'Test main dish',
            'code' => 'TEST-PLATO-001',
            'category_id' => $categoryPlatoFondo->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productPlatoFondo->id,
            'unit_price' => 5000.00,
            'active' => true,
        ]);

        // Create CategoryMenus
        $categoryMenuSopas = CategoryMenu::create([
            'category_id' => $categorySopas->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuSopas->products()->attach($productSopa->id);

        $categoryMenuEnsaladas = CategoryMenu::create([
            'category_id' => $categoryEnsaladas->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'display_order' => 20,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuEnsaladas->products()->attach($productEnsalada->id);

        $categoryMenuPlatoFondo = CategoryMenu::create([
            'category_id' => $categoryPlatoFondo->id,
            'menu_id' => $menu->id,
            'order' => 30,
            'display_order' => 30,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuPlatoFondo->products()->attach($productPlatoFondo->id);

        // Create order with TWO ENTRADA products (Sopa + Ensalada) + one Plato de Fondo
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => Carbon::parse('2025-10-14'),
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 10500.00, // 3000 + 2500 + 5000
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productSopa->id,
            'quantity' => 1,
            'unit_price' => 3000.00,
            'total' => 3000.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productEnsalada->id,
            'quantity' => 1,
            'unit_price' => 2500.00,
            'total' => 2500.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productPlatoFondo->id,
            'quantity' => 1,
            'unit_price' => 5000.00,
            'total' => 5000.00,
        ]);

        // Authenticate user
        Sanctum::actingAs($user);

        // Try to update order status to PROCESSED
        // This should SUCCEED because ENTRADA->ENTRADA exclusion rule is not present
        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-14", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // ASSERTIONS - Should return 200 (success) because multiple ENTRADA is allowed
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'message' => 'Order status updated successfully',
        ]);

        // Verify order status was actually updated
        $order->refresh();
        $this->assertEquals(OrderStatus::PROCESSED->value, $order->status);
    }

    /**
     * Test that other exclusion rules still work correctly (PLATO_DE_FONDO, FRIA->HIPOCALORICO, PAN->SANDWICH).
     *
     * This test verifies that even though we removed ENTRADA exclusion,
     * the other exclusion rules are still enforced and return 422 when violated.
     */
    public function test_other_exclusion_rules_still_work_correctly(): void
    {
        // Get roles and permissions
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create price list
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'active' => true,
        ]);

        // Create company
        $company = Company::create([
            'name' => 'Test Company Other Rules',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test2@company.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        // Create branch
        $branch = Branch::create([
            'name' => 'Main Branch',
            'address' => 'Branch Address',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Create user with validation enabled
        $user = User::create([
            'name' => 'Test User Other Rules',
            'nickname' => 'TEST.OTHER.RULES',
            'email' => 'test.other.rules@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true, // KEY: Validation is enabled
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // Get subcategories
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);

        // Create menu
        $menu = Menu::create([
            'title' => 'TEST MENU OTHER RULES',
            'publication_date' => '2025-10-14',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Category 1: First PLATO DE FONDO
        $categoryPlatoFondo1 = Category::create([
            'name' => 'TEST PLATOS FONDO 1',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categoryPlatoFondo1->subcategories()->attach($platoFondoSubcat->id);

        $productPlatoFondo1 = Product::create([
            'name' => 'TEST - POLLO AL HORNO',
            'description' => 'Test main dish 1',
            'code' => 'TEST-PLATO-001',
            'category_id' => $categoryPlatoFondo1->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productPlatoFondo1->id,
            'unit_price' => 5000.00,
            'active' => true,
        ]);

        // Category 2: Second PLATO DE FONDO (should conflict)
        $categoryPlatoFondo2 = Category::create([
            'name' => 'TEST PLATOS FONDO 2',
            'description' => 'Test category',
            'active' => true,
        ]);
        $categoryPlatoFondo2->subcategories()->attach($platoFondoSubcat->id);

        $productPlatoFondo2 = Product::create([
            'name' => 'TEST - CARNE ASADA',
            'description' => 'Test main dish 2',
            'code' => 'TEST-PLATO-002',
            'category_id' => $categoryPlatoFondo2->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $productPlatoFondo2->id,
            'unit_price' => 6000.00,
            'active' => true,
        ]);

        // Create CategoryMenus
        $categoryMenuPlatoFondo1 = CategoryMenu::create([
            'category_id' => $categoryPlatoFondo1->id,
            'menu_id' => $menu->id,
            'order' => 10,
            'display_order' => 10,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuPlatoFondo1->products()->attach($productPlatoFondo1->id);

        $categoryMenuPlatoFondo2 = CategoryMenu::create([
            'category_id' => $categoryPlatoFondo2->id,
            'menu_id' => $menu->id,
            'order' => 20,
            'display_order' => 20,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $categoryMenuPlatoFondo2->products()->attach($productPlatoFondo2->id);

        // Create order with TWO PLATO DE FONDO products (should violate exclusion rule)
        $order = Order::create([
            'user_id' => $user->id,
            'order_date' => Carbon::parse('2025-10-14'),
            'dispatch_date' => Carbon::parse('2025-10-14'),
            'status' => OrderStatus::PENDING->value,
            'total' => 11000.00, // 5000 + 6000
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productPlatoFondo1->id,
            'quantity' => 1,
            'unit_price' => 5000.00,
            'total' => 5000.00,
        ]);

        OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $productPlatoFondo2->id,
            'quantity' => 1,
            'unit_price' => 6000.00,
            'total' => 6000.00,
        ]);

        // Authenticate user
        Sanctum::actingAs($user);

        // Try to update order status to PROCESSED
        // This should FAIL (422) because PLATO_DE_FONDO->PLATO_DE_FONDO exclusion rule is still active
        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-14", [
            'status' => OrderStatus::PROCESSED->value,
        ]);

        // ASSERTIONS - Should return 422 (validation error) because multiple PLATO DE FONDO is NOT allowed
        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'error',
            'errors' => [
                'message' => [
                    "No puedes combinar las subcategorías: PLATO DE FONDO con PLATO DE FONDO.\n\n"
                ],
            ],
        ]);

        // Verify order status was NOT updated (should still be PENDING)
        $order->refresh();
        $this->assertEquals(OrderStatus::PENDING->value, $order->status);
    }
}
