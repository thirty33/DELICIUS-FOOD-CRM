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
use App\Enums\RoleName;
use App\Enums\PermissionName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Carbon\Carbon;

/**
 * Test for CategoryMenu API with show_all_products = true
 *
 * This test validates the CORRECT behavior when:
 * - CategoryMenu has show_all_products = true
 * - NO products are in the pivot table (category_menu_product is empty)
 * - Products exist in the category with active price list lines
 *
 * EXPECTED: API should return categories because products exist in CATEGORY with prices
 *
 * This test uses TDD approach - it validates the correct behavior that should work,
 * not the current buggy behavior.
 */
class CategoryMenuWithShowAllProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze time to 2025-10-14 (test creation date)
        Carbon::setTestNow('2025-10-14 00:00:00');
    }

    protected function tearDown(): void
    {
        // Release frozen time after each test
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Test that API returns categories when show_all_products = true
     * and category has products with prices (even if pivot is empty)
     *
     * Replicates production scenario (anonymized): Convenio Consolidado user type
     */
    public function test_api_returns_categories_when_show_all_products_true_and_category_has_priced_products(): void
    {
        // === CREATE ROLES AND PERMISSIONS ===
        $agreementRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $consolidatedPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);

        // === CREATE COMPANY AND PRICE LIST ===
        $company = Company::create([
            'name' => 'TEST CONSOLIDATED COMPANY',
            'fantasy_name' => 'TEST CONS',
            'address' => 'Test Address',
            'email' => 'test@testcompany.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG-TEST-001',
            'description' => 'Test company for consolidated agreement',
            'active' => true,
            'tax_id' => '123456789',
        ]);

        $priceList = PriceList::create([
            'name' => 'TEST CONSOLIDATED PRICE LIST',
            'description' => 'Test price list for consolidated agreement',
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
            'email' => 'test.cons@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'active' => true,
            'validate_subcategory_rules' => true,
            'allow_late_orders' => true,
        ]);

        $user->roles()->attach($agreementRole->id);
        $user->permissions()->attach($consolidatedPermission->id);

        // === CREATE MENU ===
        $menu = Menu::create([
            'title' => 'TEST COLACION FRIA 14/10/25',
            'description' => 'Test menu',
            'publication_date' => '2025-10-14',
            'max_order_date' => '2025-10-13 18:00:00',
            'role_id' => $agreementRole->id,
            'permissions_id' => $consolidatedPermission->id,
            'active' => true,
            'validate_subcategory_rules' => false,
        ]);

        $menu->companies()->attach($company->id);

        // === CREATE CATEGORIES WITH PRODUCTS ===

        // Category 1: CIABATTAS
        $categoryCiabattas = Category::create([
            'name' => 'TEST CIABATTAS',
            'description' => 'Test category',
            'is_active' => true,
        ]);

        // Create 3 products in CIABATTAS with prices
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::create([
                'name' => "TEST CIABATTA {$i}",
                'code' => "CIAB{$i}",
                'description' => 'Test product',
                'category_id' => $categoryCiabattas->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $priceList->id,
                'product_id' => $product->id,
                'unit_price' => 1000.00 * $i,
                'active' => true,
            ]);
        }

        // Category 2: BAGUETTES
        $categoryBaguettes = Category::create([
            'name' => 'TEST BAGUETTES',
            'description' => 'Test category',
            'is_active' => true,
        ]);

        // Create 2 products in BAGUETTES with prices
        for ($i = 1; $i <= 2; $i++) {
            $product = Product::create([
                'name' => "TEST BAGUETTE {$i}",
                'code' => "BAG{$i}",
                'description' => 'Test product',
                'category_id' => $categoryBaguettes->id,
                'active' => true,
                'measure_unit' => 'UND',
                'weight' => 0,
                'allow_sales_without_stock' => true,
            ]);

            PriceListLine::create([
                'price_list_id' => $priceList->id,
                'product_id' => $product->id,
                'unit_price' => 1500.00 * $i,
                'active' => true,
            ]);
        }

        // === CREATE CATEGORY MENUS ===
        // IMPORTANT: show_all_products = true, NO products in pivot

        $categoryMenuCiabattas = CategoryMenu::create([
            'category_id' => $categoryCiabattas->id,
            'menu_id' => $menu->id,
            'display_order' => 200,
            'show_all_products' => true, // ← KEY: show ALL products from category
            'is_active' => true,
            'mandatory_category' => true,
        ]);
        // NOTE: NO products attached to pivot (products()->attach())

        $categoryMenuBaguettes = CategoryMenu::create([
            'category_id' => $categoryBaguettes->id,
            'menu_id' => $menu->id,
            'display_order' => 210,
            'show_all_products' => true, // ← KEY: show ALL products from category
            'is_active' => true,
            'mandatory_category' => true,
        ]);
        // NOTE: NO products attached to pivot (products()->attach())

        // === TEST: Make API request ===
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/categories/{$menu->id}?page=1");

        // === ASSERTIONS ===
        // EXPECTED BEHAVIOR (TDD - test correct behavior):
        // - Should return 200
        // - Should return 2 categories (CIABATTAS and BAGUETTES)
        // - Each category should have products with prices from the category

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'current_page',
                'data' => [
                    '*' => [
                        'id',
                        'category' => [
                            'id',
                            'name',
                            'products' => [
                                '*' => [
                                    'id',
                                    'name',
                                    'code',
                                ],
                            ],
                        ],
                    ],
                ],
                'total',
            ],
        ]);

        $responseData = $response->json('data.data');

        // Should return 2 categories
        $this->assertCount(2, $responseData, 'Should return 2 categories (CIABATTAS and BAGUETTES)');

        // Verify first category (CIABATTAS)
        $this->assertEquals($categoryCiabattas->id, $responseData[0]['category']['id']);
        $this->assertEquals('TEST CIABATTAS', $responseData[0]['category']['name']);
        $this->assertCount(3, $responseData[0]['category']['products'], 'CIABATTAS should have 3 products');

        // Verify second category (BAGUETTES)
        $this->assertEquals($categoryBaguettes->id, $responseData[1]['category']['id']);
        $this->assertEquals('TEST BAGUETTES', $responseData[1]['category']['name']);
        $this->assertCount(2, $responseData[1]['category']['products'], 'BAGUETTES should have 2 products');
    }
}
