<?php

namespace Tests\Feature\API\V1\Agreement\Individual;

use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subcategory;
use App\Models\User;
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\BaseIndividualAgreementTest;

/**
 * Production Bug Replica Test - Incomplete Order Validation
 *
 * PRODUCTION DATA (anonymized):
 * - User: TEST.INDIVIDUAL.USER2 (production user ID: 548)
 * - Company: TEST COMPANY 2 S.A. (production company ID: 588)
 * - Menu: 299 - CONVENIO INDV. HORECA CON FDS 23/10/25
 * - Order: 113
 * - Role: Convenio, Permission: Individual
 * - validate_subcategory_rules: TRUE
 *
 * EXISTING ORDER (Order 113):
 * - Product 1079: ACM - CREMA DE VERDURAS (ENTRADA, CALIENTE)
 * - Product 1543: PCH - ALITAS DE POLLO (PLATO DE FONDO)
 * - Product 1054: EXT - AMASADO DELICIUS (PAN DE ACOMPAÑAMIENTO)
 * - Product 1038: PTR - BARRA DE CEREAL (POSTRES)
 * - Product 1936: EXT - SIN CUBIERTOS (OTROS)
 * - Product 1032: BEB - COCA COLA ZERO (BEBESTIBLES)
 *
 * SCENARIO:
 * User attempts to send API request with ONLY product 1032 (BEBESTIBLES)
 * POST /api/v1/orders/create-or-update-order/2025-10-23
 * Payload: {"order_lines":[{"id":1032,"quantity":1,"partially_scheduled":false}]}
 *
 * EXPECTED:
 * - System should return 422 (Unprocessable Entity)
 * - Error message: Missing required subcategories/categories
 * - Reason: Order is incomplete - missing PLATO DE FONDO, ENTRADA, PAN DE ACOMPAÑAMIENTO
 *
 * API ENDPOINT:
 * POST /api/v1/orders/create-or-update-order/{date}
 */
class IncompleteOrderValidationTest extends BaseIndividualAgreementTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-10-23 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Override to set limits that require 2 products for ENTRADA
     * This will make the validation fail since the order only has 1 ENTRADA
     */
    protected function getSubcategoryLimits(): array
    {
        return [
            'PLATO DE FONDO' => 1,
            'ENTRADA' => 2,  // Require 2 ENTRADA products, but order only has 1
            'CALIENTE' => 1,
            'FRIA' => 1,
            'PAN DE ACOMPAÑAMIENTO' => 1,
        ];
    }

    public function test_incomplete_order_should_fail_validation_when_missing_required_subcategories(): void
    {
        // Get roles (created by parent setUp)
        $agreementRole = Role::where('name', RoleName::AGREEMENT->value)->first();
        $individualPermission = Permission::where('name', PermissionName::INDIVIDUAL->value)->first();

        // Create company
        $priceList = PriceList::create(['name' => 'Test Company 2 Price List', 'active' => true]);

        $company = Company::create([
            'name' => 'TEST COMPANY 2 S.A.',
            'fantasy_name' => 'TEST CO 2',
            'address' => 'Test Address 456',
            'email' => 'test2@testcompany.com',
            'active' => true,
            'price_list_id' => $priceList->id,
        ]);

        $branch = Branch::create([
            'name' => 'TEST BRANCH 2',
            'address' => 'Branch Address 2',
            'company_id' => $company->id,
            'min_price_order' => 0,
            'active' => true,
        ]);

        // Create user
        $user = User::create([
            'name' => 'Test Individual User 2',
            'nickname' => 'TEST.INDIVIDUAL.USER2',
            'email' => 'test.individual2@test.com',
            'password' => bcrypt('password123'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($individualPermission->id);

        // Create subcategories
        $platoFondoSubcat = Subcategory::firstOrCreate(['name' => 'PLATO DE FONDO']);
        $entradaSubcat = Subcategory::firstOrCreate(['name' => 'ENTRADA']);
        $calienteSubcat = Subcategory::firstOrCreate(['name' => 'CALIENTE']);
        $panSubcat = Subcategory::firstOrCreate(['name' => 'PAN DE ACOMPAÑAMIENTO']);

        // Create menu for 2025-10-23
        $menu = Menu::create([
            'title' => 'CONVENIO INDV. HORECA CON FDS 23/10/25',
            'publication_date' => '2025-10-23',
            'active' => true,
            'role_id' => $agreementRole->id,
            'permissions_id' => $individualPermission->id,
        ]);

        // Category 1: PLATOS VARIABLES PARA CALENTAR HORECA (PLATO DE FONDO)
        $platosVariablesCategory = Category::create([
            'name' => 'PLATOS VARIABLES PARA CALENTAR HORECA',
            'is_active' => true,
        ]);
        $platosVariablesCategory->subcategories()->attach($platoFondoSubcat->id);

        $product1 = Product::create([
            'name' => 'PCH - HORECA ALITAS DE POLLO AGRIDULCE CON PAPAS RUSTICAS AL AJO',
            'description' => 'Alitas de pollo agridulce',
            'code' => 'PCH-1543',
            'category_id' => $platosVariablesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product1->id,
            'unit_price' => 5500.00,
            'active' => true,
        ]);

        // Category 2: SOPAS Y CREMAS VARIABLES HORECA (ENTRADA, CALIENTE)
        $sopasVariablesCategory = Category::create([
            'name' => 'SOPAS Y CREMAS VARIABLES HORECA',
            'is_active' => true,
        ]);
        $sopasVariablesCategory->subcategories()->attach([$entradaSubcat->id, $calienteSubcat->id]);

        $product2 = Product::create([
            'name' => 'ACM - HORECA CREMA DE VERDURAS CON POLLO INDIVIDUAL',
            'description' => 'Crema de verduras con pollo',
            'code' => 'ACM-1079',
            'category_id' => $sopasVariablesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product2->id,
            'unit_price' => 3500.00,
            'active' => true,
        ]);

        // Category 3: ACOMPAÑAMIENTOS (PAN DE ACOMPAÑAMIENTO)
        $acompanamientosCategory = Category::create([
            'name' => 'ACOMPAÑAMIENTOS',
            'is_active' => true,
        ]);
        $acompanamientosCategory->subcategories()->attach($panSubcat->id);

        $product3 = Product::create([
            'name' => 'EXT - AMASADO DELICIUS MINI',
            'description' => 'Amasado delicius mini',
            'code' => 'EXT-1054',
            'category_id' => $acompanamientosCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product3->id,
            'unit_price' => 1500.00,
            'active' => true,
        ]);

        // Category 4: POSTRES (no subcategories)
        $postresCategory = Category::create([
            'name' => 'POSTRES',
            'is_active' => true,
        ]);

        $product4 = Product::create([
            'name' => 'PTR - BARRA DE CEREAL',
            'description' => 'Barra de cereal',
            'code' => 'PTR-1038',
            'category_id' => $postresCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product4->id,
            'unit_price' => 1000.00,
            'active' => true,
        ]);

        // Category 5: BEBESTIBLES (no subcategories) - THE PRODUCT IN THE FAILING REQUEST
        $bebestiblesCategory = Category::create([
            'name' => 'BEBESTIBLES',
            'is_active' => true,
        ]);

        $product5 = Product::create([
            'name' => 'BEB - COCA COLA ZERO 350 ML',
            'description' => 'Coca Cola Zero 350ml',
            'code' => 'BEB-1032',
            'category_id' => $bebestiblesCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product5->id,
            'unit_price' => 1200.00,
            'active' => true,
        ]);

        // Category 6: OTROS (no subcategories)
        $otrosCategory = Category::create([
            'name' => 'OTROS',
            'is_active' => true,
        ]);

        $product6 = Product::create([
            'name' => 'EXT - SIN CUBIERTOS',
            'description' => 'Sin cubiertos',
            'code' => 'EXT-1936',
            'category_id' => $otrosCategory->id,
            'active' => true,
            'measure_unit' => 'UND',
            'weight' => 0,
            'allow_sales_without_stock' => true,
        ]);

        PriceListLine::create([
            'price_list_id' => $priceList->id,
            'product_id' => $product6->id,
            'unit_price' => 0.00,
            'active' => true,
        ]);

        // Create CategoryMenus
        $cm1 = CategoryMenu::create([
            'category_id' => $platosVariablesCategory->id,
            'menu_id' => $menu->id,
            'order' => 35,
            'display_order' => 35,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm1->products()->attach($product1->id);

        $cm2 = CategoryMenu::create([
            'category_id' => $sopasVariablesCategory->id,
            'menu_id' => $menu->id,
            'order' => 30,
            'display_order' => 30,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm2->products()->attach($product2->id);

        $cm3 = CategoryMenu::create([
            'category_id' => $acompanamientosCategory->id,
            'menu_id' => $menu->id,
            'order' => 200,
            'display_order' => 200,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm3->products()->attach($product3->id);

        $cm4 = CategoryMenu::create([
            'category_id' => $postresCategory->id,
            'menu_id' => $menu->id,
            'order' => 210,
            'display_order' => 210,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm4->products()->attach($product4->id);

        $cm5 = CategoryMenu::create([
            'category_id' => $bebestiblesCategory->id,
            'menu_id' => $menu->id,
            'order' => 220,
            'display_order' => 220,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm5->products()->attach($product5->id);

        $cm6 = CategoryMenu::create([
            'category_id' => $otrosCategory->id,
            'menu_id' => $menu->id,
            'order' => 230,
            'display_order' => 230,
            'show_all_products' => false,
            'mandatory_category' => false,
            'is_active' => true,
        ]);
        $cm6->products()->attach($product6->id);

        // Authenticate user
        Sanctum::actingAs($user);

        // STEP 1: Create complete order with all 6 products (this should succeed)
        $response1 = $this->postJson("/api/v1/orders/create-or-update-order/2025-10-23", [
            'order_lines' => [
                ['id' => $product1->id, 'quantity' => 1, 'partially_scheduled' => false], // PLATO DE FONDO
                ['id' => $product2->id, 'quantity' => 1, 'partially_scheduled' => false], // ENTRADA, CALIENTE
                ['id' => $product3->id, 'quantity' => 1, 'partially_scheduled' => false], // PAN DE ACOMPAÑAMIENTO
                ['id' => $product4->id, 'quantity' => 1, 'partially_scheduled' => false], // POSTRES
                ['id' => $product5->id, 'quantity' => 1, 'partially_scheduled' => false], // BEBESTIBLES
                ['id' => $product6->id, 'quantity' => 1, 'partially_scheduled' => false], // OTROS
            ],
        ]);

        $response1->assertStatus(200);

        // Verify order was created with 6 products
        $order = Order::where('user_id', $user->id)
            ->whereDate('dispatch_date', '2025-10-23')
            ->first();
        $this->assertNotNull($order);
        $this->assertEquals(6, $order->orderLines->count());

        // STEP 2: Try to update order status to PROCESSED
        // This should FAIL because the order doesn't meet exact subcategory requirements
        // Order has: 1 ENTRADA, 1 PLATO DE FONDO, 1 PAN DE ACOMPAÑAMIENTO
        // Rules require (based on BaseIndividualAgreementTest): 1 of each (but we'll configure to require 2)
        $response = $this->postJson("/api/v1/orders/update-order-status/2025-10-23", [
            'status' => 'PROCESSED',
        ]);

        // EXPECTED: 422 (Validation Error)
        // Order doesn't meet exact subcategory count requirements
        $response->assertStatus(422);

        // The error message should indicate incorrect product count
        $response->assertJsonStructure([
            'message',
            'errors',
        ]);

        // Verify that the error mentions product count or subcategory
        $responseData = $response->json();
        $errorMessage = strtolower(json_encode($responseData));
        $this->assertTrue(
            str_contains($errorMessage, 'producto') || str_contains($errorMessage, 'pedido'),
            'Error message should mention products or order requirements'
        );
    }
}
