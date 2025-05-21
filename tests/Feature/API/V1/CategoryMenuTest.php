<?php

namespace Tests\Feature\API\V1;

use App\Enums\Weekday;
use App\Models\Category;
use App\Models\CategoryLine;
use App\Models\CategoryMenu;
use App\Models\Company;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\PriceListLine;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('api:v1')]
#[Group('api:v1:categories-menu')]
class CategoryMenuTest extends TestCase
{
    use RefreshDatabase;
    
    /**
     * Test that the categories endpoint returns category data when all prerequisites are met.
     */
    #[Test]
    public function category_menu_api_returns_data_when_prerequisites_met(): void
    {

        Log::info("test data:", [
            'config' => config('app.locale'),
            'env' => env('APP_LOCALE')
        ]);

        Carbon::setLocale(config('app.locale'));

        // Set timezone
        $timezone = config('app.timezone', 'UTC');
        
        // 1. Create Product and Category
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Description for test category',
            'is_active' => true,
        ]);
        
        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Description for test product',
            'price' => 1000, // $10.00
            'category_id' => $category->id,
            'code' => 'PROD-TEST',
            'active' => 1,
            'measure_unit' => 'UND',
            'price_list' => 1000,
            'stock' => 100,
            'weight' => '1.0',
            'allow_sales_without_stock' => 1,
        ]);
        
        // 2. Create CategoryLine for Wednesday (Miércoles)
        $categoryLine = CategoryLine::create([
            'category_id' => $category->id,
            'weekday' => Weekday::WEDNESDAY->value,
            'preparation_days' => 1,
            'maximum_order_time' => '14:00:00',
            'active' => true
        ]);
        
        // 3. Set current date to Monday, May 19, 2025
        $testDate = Carbon::create(2025, 5, 19, 9, 0, 0, $timezone);
        $this->travelTo($testDate);
        
        // 4. Create User, Company and associate them
        $role = Role::create(['name' => 'Convenio']);
        $permission = Permission::create(['name' => 'Consolidado']);
        
        $user = User::factory()->create([
            'allow_late_orders' => true,
        ]);
        
        $user->roles()->attach($role);
        $user->permissions()->attach($permission);
        
        $company = Company::create([
            'name' => 'Test Company',
            'fantasy_name' => 'Test Company',
            'address' => 'Test Address',
            'email' => 'test@example.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG123456',
            'description' => 'Test company description',
            'active' => true,
            'tax_id' => '123456789',
        ]);
        
        // Associate User with Company
        $user->company_id = $company->id;
        $user->save();
        
        // 5. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);
        
        $company->price_list_id = $priceList->id;
        $company->save();
        
        // 6. Create PriceListLine for Product
        $priceListLine = PriceListLine::create([
            'unit_price' => 1000, // $10.00
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
        ]);
        
        // 7. Create Menu for May 20, 2025
        $menu = Menu::create([
            'title' => 'Menu for May 20, 2025',
            'description' => 'Test menu description',
            'publication_date' => '2025-05-21',
            'max_order_date' => '2025-05-19 14:00:00', // Order before 2 PM on May 19
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);
        
        // 8. Create CategoryMenu to associate Category with Menu
        $categoryMenu = CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 10,
            'show_all_products' => true,
            'mandatory_category' => false,
        ]);
        
        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;
        
        // 9. Make API request and verify response
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));
        
        // Debug response content if needed
        // \Log::info('API Response:', ['data' => $response->json()]);
        
        // 10. Assert response structure
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'current_page',
                    'data' => [
                        '*' => [
                            'id',
                            'order',
                            'show_all_products',
                            'category_id',
                            'menu_id',
                            'category' => [
                                'id',
                                'name',
                                'description',
                                'products' => [
                                    '*' => [
                                        'id',
                                        'name',
                                        'description',
                                        'price',
                                        'image',
                                        'category_id',
                                        'code',
                                        'active',
                                    ]
                                ],
                                'category_lines',
                                'subcategories'
                            ],
                            'menu',
                            'products'
                        ]
                    ],
                    'first_page_url',
                    'from',
                    'last_page',
                    'last_page_url',
                    'links',
                    'next_page_url',
                    'path',
                    'per_page',
                    'prev_page_url',
                    'to',
                    'total'
                ]
            ]);
        
        // Verify we have at least one record in data.data
        $responseData = $response->json('data.data');
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        $this->assertGreaterThanOrEqual(1, count($responseData));
        
        // Verify the category data is correct
        $firstCategory = $responseData[0]['category'];
        $this->assertEquals($category->id, $firstCategory['id']);
        $this->assertEquals($category->name, $firstCategory['name']);
        
        // Verify the product data is present
        $this->assertNotEmpty($firstCategory['products']);
        $this->assertEquals($product->id, $firstCategory['products'][0]['id']);
        $this->assertEquals($product->name, $firstCategory['products'][0]['name']);
        
        // Verify the category line information is present
        $this->assertNotEmpty($firstCategory['category_lines']);
        $this->assertEquals(Weekday::WEDNESDAY->toSpanish(), $firstCategory['category_lines'][0]['weekday']);
        
        // Verify the menu information is correct
        $this->assertEquals($menu->id, $responseData[0]['menu']['id']);
        $this->assertEquals($menu->title, $responseData[0]['menu']['title']);
        
        // Return to the present
        $this->travelBack();
    }
}