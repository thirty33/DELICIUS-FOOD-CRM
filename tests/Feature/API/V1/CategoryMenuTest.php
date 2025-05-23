<?php

namespace Tests\Feature\API\V1;

use App\Enums\PermissionName;
use App\Enums\RoleName;
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

    /**
     * Test that the maximum_order_time message in category_lines is formatted correctly for each day of the week
     */
    #[Test]
    public function category_menu_api_shows_correct_maximum_order_time_message_for_each_weekday(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create Product and Category
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Description for test category',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Description for test product',
            'price' => 1000,
            'category_id' => $category->id,
            'code' => 'PROD-TEST',
            'active' => 1,
            'measure_unit' => 'UND',
            'price_list' => 1000,
            'stock' => 100,
            'weight' => '1.0',
            'allow_sales_without_stock' => 1,
        ]);

        // 2. Create CategoryLine for each day of the week with incremental preparation_days
        $weekdays = [
            Weekday::MONDAY->value => 1,
            Weekday::TUESDAY->value => 2,
            Weekday::WEDNESDAY->value => 3,
            Weekday::THURSDAY->value => 4,
            Weekday::FRIDAY->value => 5,
            Weekday::SATURDAY->value => 6,
            Weekday::SUNDAY->value => 7
        ];

        foreach ($weekdays as $weekdayValue => $preparationDay) {
            CategoryLine::create([
                'category_id' => $category->id,
                'weekday' => $weekdayValue,
                'preparation_days' => $preparationDay,
                'maximum_order_time' => '14:00:00',
                'active' => true
            ]);
        }

        // 3. Create User, Company and associate them
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

        // 4. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        $company->price_list_id = $priceList->id;
        $company->save();

        // 5. Create PriceListLine for Product
        PriceListLine::create([
            'unit_price' => 1000,
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
        ]);

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 6. Test each day of the week
        $testDates = [
            'Monday' => Carbon::create(2025, 5, 19, 9, 0, 0, $timezone),
            'Tuesday' => Carbon::create(2025, 5, 20, 9, 0, 0, $timezone),
            'Wednesday' => Carbon::create(2025, 5, 21, 9, 0, 0, $timezone),
            'Thursday' => Carbon::create(2025, 5, 22, 9, 0, 0, $timezone),
            'Friday' => Carbon::create(2025, 5, 23, 9, 0, 0, $timezone),
            'Saturday' => Carbon::create(2025, 5, 24, 9, 0, 0, $timezone),
            'Sunday' => Carbon::create(2025, 5, 25, 9, 0, 0, $timezone),
        ];

        // Days of the week to create menus for (0 = current day, 1 = next day, etc.)
        $daysOffset = [0, 1, 2, 3, 4, 5, 6];

        foreach ($testDates as $currentDayName => $testDate) {
            // Travel to the test date
            $this->travelTo($testDate);

            // For each day of the current week, create a menu and test it
            foreach ($daysOffset as $offset) {
                // Create a menu for this day
                $menuDate = $testDate->copy()->addDays($offset);
                $menu = Menu::create([
                    'title' => "Menu for {$menuDate->format('F j, Y')}",
                    'description' => 'Test menu description',
                    'publication_date' => $menuDate->format('Y-m-d'),
                    'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
                    'role_id' => $role->id,
                    'permissions_id' => $permission->id,
                    'active' => true,
                ]);

                // Create CategoryMenu to associate Category with Menu
                CategoryMenu::create([
                    'category_id' => $category->id,
                    'menu_id' => $menu->id,
                    'display_order' => 10,
                    'show_all_products' => true,
                    'mandatory_category' => false,
                ]);

                // Make API request
                $response = $this->withToken($token)
                    ->getJson(route('v1.categories.show', $menu->id));

                // Assert response status
                $response->assertStatus(200);

                // Get the category_lines data
                $responseData = $response->json('data.data');
                $this->assertIsArray($responseData);
                $this->assertNotEmpty($responseData);

                $categoryLines = $responseData[0]['category']['category_lines'];
                $this->assertNotEmpty($categoryLines);

                // Determine the expected weekday for the menu
                $menuDayName = $menuDate->isoFormat('dddd');
                $menuDayNameCapitalized = ucfirst(strtolower($menuDayName));

                // Verify the weekday name is correct
                $this->assertEquals($menuDayNameCapitalized, $categoryLines[0]['weekday']);

                // Get preparation days for the menu day
                $menuDayWeekday = Weekday::fromSpanish($menuDayNameCapitalized);
                $preparationDays = $weekdays[$menuDayWeekday->value];

                // Calculate the order date by subtracting preparation days from the menu date
                $orderDate = $menuDate->copy()->subDays($preparationDays);
                $formattedDate = $orderDate->isoFormat('dddd D [de] MMMM [de] YYYY');

                // Build expected message
                $expectedMessage = "Disponible hasta el {$formattedDate} a las 14:00";

                // Log for debugging if needed
                Log::info("Testing menu for {$menuDayNameCapitalized}", [
                    'current_day' => $currentDayName,
                    'menu_date' => $menuDate->format('Y-m-d'),
                    'preparation_days' => $preparationDays,
                    'order_date' => $orderDate->format('Y-m-d'),
                    'expected_message' => $expectedMessage,
                    'actual_message' => $categoryLines[0]['maximum_order_time']
                ]);

                // Verify the maximum_order_time message
                $this->assertEquals($expectedMessage, $categoryLines[0]['maximum_order_time']);

                // Clean up - delete the menu for this test
                $menu->delete();
            }
        }

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that category_lines array is empty when all CategoryLine records have active=false
     */
    #[Test]
    public function category_menu_api_returns_empty_category_lines_when_inactive(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create Product and Category
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Description for test category',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Description for test product',
            'price' => 1000,
            'category_id' => $category->id,
            'code' => 'PROD-TEST',
            'active' => 1,
            'measure_unit' => 'UND',
            'price_list' => 1000,
            'stock' => 100,
            'weight' => '1.0',
            'allow_sales_without_stock' => 1,
        ]);

        // 2. Create CategoryLine but set active=false
        CategoryLine::create([
            'category_id' => $category->id,
            'weekday' => Weekday::WEDNESDAY->value,
            'preparation_days' => 1,
            'maximum_order_time' => '14:00:00',
            'active' => false // Set to inactive
        ]);

        // 3. Create User, Company and associate them
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

        // 4. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        $company->price_list_id = $priceList->id;
        $company->save();

        // 5. Create PriceListLine for Product
        PriceListLine::create([
            'unit_price' => 1000,
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
        ]);

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 6. Set current date to Wednesday (which matches our CategoryLine's weekday)
        $testDate = Carbon::create(2025, 5, 21, 9, 0, 0, $timezone); // Wednesday, May 21, 2025
        $this->travelTo($testDate);

        // 7. Create Menu for the same day
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 8. Create CategoryMenu to associate Category with Menu
        CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 10,
            'show_all_products' => true,
            'mandatory_category' => false,
        ]);

        // 9. Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));

        // 10. Assert response status
        $response->assertStatus(200);

        // 11. Get the category_lines data
        $responseData = $response->json('data.data');
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);

        // 12. Verify category_lines array is empty
        $categoryLines = $responseData[0]['category']['category_lines'];
        $this->assertIsArray($categoryLines);
        $this->assertEmpty($categoryLines);

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that category_lines array is empty when no CategoryLine records exist
     */
    #[Test]
    public function category_menu_api_returns_empty_category_lines_when_none_exist(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create Product and Category
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Description for test category',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Description for test product',
            'price' => 1000,
            'category_id' => $category->id,
            'code' => 'PROD-TEST',
            'active' => 1,
            'measure_unit' => 'UND',
            'price_list' => 1000,
            'stock' => 100,
            'weight' => '1.0',
            'allow_sales_without_stock' => 1,
        ]);

        // 2. Skip creating any CategoryLine records

        // 3. Create User, Company and associate them
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

        // 4. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        $company->price_list_id = $priceList->id;
        $company->save();

        // 5. Create PriceListLine for Product
        PriceListLine::create([
            'unit_price' => 1000,
            'price_list_id' => $priceList->id,
            'product_id' => $product->id,
        ]);

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 6. Set current date
        $testDate = Carbon::create(2025, 5, 21, 9, 0, 0, $timezone); // Wednesday, May 21, 2025
        $this->travelTo($testDate);

        // 7. Create Menu for the same day
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 8. Create CategoryMenu to associate Category with Menu
        CategoryMenu::create([
            'category_id' => $category->id,
            'menu_id' => $menu->id,
            'display_order' => 10,
            'show_all_products' => true,
            'mandatory_category' => false,
        ]);

        // 9. Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));

        // 10. Assert response status
        $response->assertStatus(200);

        // 11. Get the category_lines data
        $responseData = $response->json('data.data');
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);

        // 12. Verify category_lines array is empty
        $categoryLines = $responseData[0]['category']['category_lines'];
        $this->assertIsArray($categoryLines);
        $this->assertEmpty($categoryLines);

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that categories are returned in the correct order based on display_order
     */
    #[Test]
    public function category_menu_api_returns_categories_in_correct_order(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create 5 Categories
        $categories = [];
        for ($i = 1; $i <= 5; $i++) {
            $categories[$i] = Category::create([
                'name' => "Test Category {$i}",
                'description' => "Description for test category {$i}",
                'is_active' => true,
            ]);

            // Create 5 Products for each Category
            for ($j = 1; $j <= 5; $j++) {
                $product = Product::create([
                    'name' => "Product {$j} for Category {$i}",
                    'description' => "Description for product {$j} in category {$i}",
                    'price' => 1000 + ($i * 100) + $j,
                    'category_id' => $categories[$i]->id,
                    'code' => "PROD-{$i}-{$j}",
                    'active' => 1,
                    'measure_unit' => 'UND',
                    'price_list' => 1000 + ($i * 100) + $j,
                    'stock' => 100,
                    'weight' => '1.0',
                    'allow_sales_without_stock' => 1,
                ]);
            }

            // Create CategoryLine for each category
            CategoryLine::create([
                'category_id' => $categories[$i]->id,
                'weekday' => Weekday::WEDNESDAY->value,
                'preparation_days' => 1,
                'maximum_order_time' => '14:00:00',
                'active' => true
            ]);
        }

        // 2. Create User, Company and associate them
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

        // 3. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        $company->price_list_id = $priceList->id;
        $company->save();

        // 4. Create PriceListLine for each Product
        foreach ($categories as $categoryId => $category) {
            for ($j = 1; $j <= 5; $j++) {
                // Get product for this category
                $product = Product::where('category_id', $category->id)
                    ->where('name', "Product {$j} for Category {$categoryId}")
                    ->first();

                if ($product) {
                    PriceListLine::create([
                        'unit_price' => 1000 + ($categoryId * 100) + $j,
                        'price_list_id' => $priceList->id,
                        'product_id' => $product->id,
                    ]);
                }
            }
        }

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 5. Set current date to Wednesday
        $testDate = Carbon::create(2025, 5, 21, 9, 0, 0, $timezone); // Wednesday, May 21, 2025
        $this->travelTo($testDate);

        // 6. Create Menu
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 7. Create CategoryMenu associations with specific display order
        // Create in reverse order to make sure ordering is based on display_order, not creation time
        $displayOrders = [50, 40, 30, 20, 10];
        foreach ($categories as $index => $category) {
            CategoryMenu::create([
                'category_id' => $category->id,
                'menu_id' => $menu->id,
                'display_order' => $displayOrders[$index - 1], // Use displayOrders array
                'show_all_products' => true,
                'mandatory_category' => false,
            ]);
        }

        // 8. Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));

        // 9. Assert response status
        $response->assertStatus(200);

        // 10. Get the data
        $responseData = $response->json('data.data');
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);

        // 11. Verify there are 5 categories
        $this->assertCount(5, $responseData);

        // 12. Verify categories are ordered by display_order
        $expectedOrder = [10, 20, 30, 40, 50];
        foreach ($responseData as $index => $categoryMenu) {
            $this->assertEquals($expectedOrder[$index], $categoryMenu['order']);

            // Additional check: make sure display_order is ascending
            if ($index > 0) {
                $this->assertGreaterThan(
                    $responseData[$index - 1]['order'],
                    $categoryMenu['order'],
                    "Category at index {$index} should have a higher order than the previous one"
                );
            }
        }

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that products are correctly placed in category.products or products based on show_all_products flag
     */
    #[Test]
    public function category_menu_api_places_products_correctly_based_on_show_all_products_flag(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create 5 Categories
        $categories = [];
        for ($i = 1; $i <= 5; $i++) {
            $categories[$i] = Category::create([
                'name' => "Test Category {$i}",
                'description' => "Description for test category {$i}",
                'is_active' => true,
            ]);

            // Create CategoryLine for each category
            CategoryLine::create([
                'category_id' => $categories[$i]->id,
                'weekday' => Weekday::FRIDAY->value,
                'preparation_days' => 1,
                'maximum_order_time' => '14:00:00',
                'active' => true
            ]);

            // Create 3 Products for each Category
            for ($j = 1; $j <= 3; $j++) {
                Product::create([
                    'name' => "Product {$j} for Category {$i}",
                    'description' => "Description for product {$j} in category {$i}",
                    'price' => 1000 + ($i * 100) + $j,
                    'category_id' => $categories[$i]->id,
                    'code' => "PROD-{$i}-{$j}",
                    'active' => 1,
                    'measure_unit' => 'UND',
                    'price_list' => 1000 + ($i * 100) + $j,
                    'stock' => 100,
                    'weight' => '1.0',
                    'allow_sales_without_stock' => 1,
                ]);
            }
        }

        // 2. Create User, Company and associate them
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

        // 3. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        $company->price_list_id = $priceList->id;
        $company->save();

        // 4. Create PriceListLine for each Product
        foreach ($categories as $categoryId => $category) {
            $products = Product::where('category_id', $category->id)->get();

            foreach ($products as $product) {
                PriceListLine::create([
                    'unit_price' => 1000 + ($categoryId * 100) + rand(1, 100),
                    'price_list_id' => $priceList->id,
                    'product_id' => $product->id,
                ]);
            }
        }

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 5. Set current date to Friday
        $testDate = Carbon::create(2025, 5, 23, 9, 0, 0, $timezone); // Friday, May 23, 2025
        $this->travelTo($testDate);

        // 6. Create Menu
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 7. Create CategoryMenu associations with alternating show_all_products values
        $categoryMenuMap = []; // Map to track category_id to show_all_products
        $displayOrder = 10;

        foreach ($categories as $index => $category) {
            // Alternate show_all_products (odd indices true, even indices false)
            $showAllProducts = ($index % 2 != 0);

            // Store the configuration for verification later
            $categoryMenuMap[$category->id] = $showAllProducts;

            Log::info("Creating CategoryMenu for Category", [
                'index' => $index,
                'category_id' => $category->id,
                'show_all_products' => $showAllProducts
            ]);

            $categoryMenu = CategoryMenu::create([
                'category_id' => $category->id,
                'menu_id' => $menu->id,
                'display_order' => $displayOrder,
                'show_all_products' => $showAllProducts,
                'mandatory_category' => false,
            ]);

            $displayOrder += 10;

            // For categories with show_all_products=false, add specific products to the CategoryMenu
            if (!$showAllProducts) {
                // Get the first 2 products for this category
                $products = Product::where('category_id', $category->id)->take(2)->get();

                // Associate these products with the CategoryMenu
                foreach ($products as $product) {
                    \DB::table('category_menu_product')->insert([
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id,
                    ]);

                    Log::info("Added product to CategoryMenu", [
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id
                    ]);
                }
            }
        }

        // 8. Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));

        // 9. Assert response status
        $response->assertStatus(200);

        // 10. Get the data
        $responseData = $response->json('data.data');
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);

        // 11. Verify there are 5 categories
        $this->assertCount(5, $responseData);

        // 12. Check product placement for each CategoryMenu
        foreach ($responseData as $index => $categoryMenuData) {
            // Get the category ID from the response
            $categoryId = $categoryMenuData['category_id'];

            // Log detailed information about the category menu
            Log::info("Processing CategoryMenu from response", [
                'index' => $index,
                'category_id' => $categoryId,
                'category_name' => $categoryMenuData['category']['name'] ?? 'Unknown',
                'show_all_products' => $categoryMenuData['show_all_products'] ?? 'Unknown',
                'has_category_products' => !empty($categoryMenuData['category']['products']),
                'has_direct_products' => !empty($categoryMenuData['products']),
            ]);

            // Use the show_all_products value directly from the response
            $showAllProducts = $categoryMenuData['show_all_products'];

            if ($showAllProducts) {
                // Products should be in category.products, and products array should be empty
                $this->assertNotEmpty(
                    $categoryMenuData['category']['products'],
                    "Category {$categoryId} should have products in category.products"
                );
                $this->assertEmpty(
                    $categoryMenuData['products'],
                    "Category {$categoryId} should have empty products array"
                );

                // Verify all 3 products are included
                $this->assertCount(
                    3,
                    $categoryMenuData['category']['products'],
                    "Category {$categoryId} should have 3 products in category.products"
                );
            } else {
                // Products should be in products array, and category.products should be empty
                $this->assertEmpty(
                    $categoryMenuData['category']['products'],
                    "Category {$categoryId} should have empty category.products array"
                );
                $this->assertNotEmpty(
                    $categoryMenuData['products'],
                    "Category {$categoryId} should have products in products array"
                );

                // Verify only 2 specific products are included (the ones we added to category_menu_product)
                $this->assertCount(
                    2,
                    $categoryMenuData['products'],
                    "Category {$categoryId} should have 2 products in products array"
                );
            }
        }

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that products without price_list_line are excluded from the API response
     */
    #[Test]
    public function category_menu_api_excludes_products_without_price_list_lines(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create 3 Categories
        $categories = [];
        for ($i = 1; $i <= 3; $i++) {
            $categories[$i] = Category::create([
                'name' => "Test Category {$i}",
                'description' => "Description for test category {$i}",
                'is_active' => true,
            ]);

            // Create CategoryLine for each category
            CategoryLine::create([
                'category_id' => $categories[$i]->id,
                'weekday' => Weekday::FRIDAY->value,
                'preparation_days' => 1,
                'maximum_order_time' => '14:00:00',
                'active' => true
            ]);

            // Create 5 Products for each Category
            for ($j = 1; $j <= 5; $j++) {
                Product::create([
                    'name' => "Product {$j} for Category {$i}",
                    'description' => "Description for product {$j} in category {$i}",
                    'price' => 1000 + ($i * 100) + $j,
                    'category_id' => $categories[$i]->id,
                    'code' => "PROD-{$i}-{$j}",
                    'active' => 1,
                    'measure_unit' => 'UND',
                    'price_list' => 1000 + ($i * 100) + $j,
                    'stock' => 100,
                    'weight' => '1.0',
                    'allow_sales_without_stock' => 1,
                ]);
            }
        }

        // 2. Create User, Company and associate them
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

        // 3. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        $company->price_list_id = $priceList->id;
        $company->save();

        // 4. Create PriceListLine only for SOME products (products 1, 2, 3 but NOT 4, 5)
        // Keep track of which products have price list lines
        $productsWithPriceListLines = [];

        foreach ($categories as $categoryId => $category) {
            $products = Product::where('category_id', $category->id)->get();

            foreach ($products as $product) {
                // Only create PriceListLine for products 1, 2, and 3 (exclude 4 and 5)
                if (
                    strpos($product->name, 'Product 1') !== false ||
                    strpos($product->name, 'Product 2') !== false ||
                    strpos($product->name, 'Product 3') !== false
                ) {

                    PriceListLine::create([
                        'unit_price' => 1000 + ($categoryId * 100) + rand(1, 100),
                        'price_list_id' => $priceList->id,
                        'product_id' => $product->id,
                    ]);

                    $productsWithPriceListLines[] = $product->id;

                    Log::info("Created PriceListLine for product", [
                        'product_id' => $product->id,
                        'product_name' => $product->name
                    ]);
                } else {
                    Log::info("Skipped creating PriceListLine for product", [
                        'product_id' => $product->id,
                        'product_name' => $product->name
                    ]);
                }
            }
        }

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 5. Set current date to Friday
        $testDate = Carbon::create(2025, 5, 23, 9, 0, 0, $timezone); // Friday, May 23, 2025
        $this->travelTo($testDate);

        // 6. Create Menu
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 7. Create CategoryMenu associations - both with show_all_products true and false
        $displayOrder = 10;

        foreach ($categories as $index => $category) {
            // First category with show_all_products = true
            // Second category with show_all_products = false
            // Third category with show_all_products = true
            $showAllProducts = $index != 2;

            Log::info("Creating CategoryMenu", [
                'category_id' => $category->id,
                'show_all_products' => $showAllProducts
            ]);

            $categoryMenu = CategoryMenu::create([
                'category_id' => $category->id,
                'menu_id' => $menu->id,
                'display_order' => $displayOrder,
                'show_all_products' => $showAllProducts,
                'mandatory_category' => false,
            ]);

            $displayOrder += 10;

            // For categories with show_all_products=false, add all products to the CategoryMenu
            // even those without price_list_line
            if (!$showAllProducts) {
                $products = Product::where('category_id', $category->id)->get();

                foreach ($products as $product) {
                    \DB::table('category_menu_product')->insert([
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id,
                    ]);

                    Log::info("Added product to CategoryMenu", [
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id,
                        'has_price_list_line' => in_array($product->id, $productsWithPriceListLines)
                    ]);
                }
            }
        }

        // 8. Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));

        // 9. Assert response status
        $response->assertStatus(200);

        // 10. Get the data
        $responseData = $response->json('data.data');
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);

        // 11. Verify there are 3 categories
        $this->assertCount(3, $responseData);

        // 12. Check product filtering for each CategoryMenu
        foreach ($responseData as $index => $categoryMenuData) {
            $categoryId = $categoryMenuData['category_id'];
            $showAllProducts = $categoryMenuData['show_all_products'];

            Log::info("Checking CategoryMenu in response", [
                'index' => $index,
                'category_id' => $categoryId,
                'show_all_products' => $showAllProducts,
                'category_products_count' => count($categoryMenuData['category']['products'] ?? []),
                'direct_products_count' => count($categoryMenuData['products'] ?? [])
            ]);

            if ($showAllProducts) {
                // Products should be in category.products
                $this->assertNotEmpty(
                    $categoryMenuData['category']['products'],
                    "Category {$categoryId} should have products in category.products"
                );

                // Should only have 3 products (1, 2, 3) instead of all 5, because 4 and 5 don't have price_list_line
                $this->assertCount(
                    3,
                    $categoryMenuData['category']['products'],
                    "Category {$categoryId} should have only 3 products in category.products (those with price_list_line)"
                );

                // Verify only products with price_list_line are included
                foreach ($categoryMenuData['category']['products'] as $product) {
                    $this->assertContains(
                        $product['id'],
                        $productsWithPriceListLines,
                        "Product {$product['id']} without price_list_line should not be included"
                    );

                    $this->assertTrue(
                        strpos($product['name'], 'Product 1') !== false ||
                            strpos($product['name'], 'Product 2') !== false ||
                            strpos($product['name'], 'Product 3') !== false,
                        "Only products 1, 2, or 3 should be included"
                    );
                }
            } else {
                // Products should be in products array
                $this->assertNotEmpty(
                    $categoryMenuData['products'],
                    "Category {$categoryId} should have products in products array"
                );

                // Should only have 3 products (1, 2, 3) instead of all 5, because 4 and 5 don't have price_list_line
                $this->assertCount(
                    3,
                    $categoryMenuData['products'],
                    "Category {$categoryId} should have only 3 products in products array (those with price_list_line)"
                );

                // Verify only products with price_list_line are included
                foreach ($categoryMenuData['products'] as $product) {
                    $this->assertContains(
                        $product['id'],
                        $productsWithPriceListLines,
                        "Product {$product['id']} without price_list_line should not be included"
                    );

                    $this->assertTrue(
                        strpos($product['name'], 'Product 1') !== false ||
                            strpos($product['name'], 'Product 2') !== false ||
                            strpos($product['name'], 'Product 3') !== false,
                        "Only products 1, 2, or 3 should be included"
                    );
                }
            }
        }

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that products with inactive price list lines are excluded from the API response
     */
    #[Test]
    public function category_menu_api_excludes_products_with_inactive_price_list_lines(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create 3 Categories
        $categories = [];
        for ($i = 1; $i <= 3; $i++) {
            $categories[$i] = Category::create([
                'name' => "Test Category {$i}",
                'description' => "Description for test category {$i}",
                'is_active' => true,
            ]);

            // Create CategoryLine for each category
            CategoryLine::create([
                'category_id' => $categories[$i]->id,
                'weekday' => Weekday::FRIDAY->value,
                'preparation_days' => 1,
                'maximum_order_time' => '14:00:00',
                'active' => true
            ]);

            // Create 5 Products for each Category - all products are active
            for ($j = 1; $j <= 5; $j++) {
                Product::create([
                    'name' => "Product {$j} for Category {$i}",
                    'description' => "Description for product {$j} in category {$i}",
                    'price' => 1000 + ($i * 100) + $j,
                    'category_id' => $categories[$i]->id,
                    'code' => "PROD-{$i}-{$j}",
                    'active' => 1,
                    'measure_unit' => 'UND',
                    'price_list' => 1000 + ($i * 100) + $j,
                    'stock' => 100,
                    'weight' => '1.0',
                    'allow_sales_without_stock' => 1,
                ]);
            }
        }

        // 2. Create User, Company and associate them
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

        // 3. Create PriceList and associate Company with it
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        $company->price_list_id = $priceList->id;
        $company->save();

        // 4. Create PriceListLine for ALL products, but with active=false for products 4 and 5
        // Keep track of products with active and inactive price list lines
        $productsWithActivePriceLines = [];
        $productsWithInactivePriceLines = [];

        foreach ($categories as $categoryId => $category) {
            $products = Product::where('category_id', $category->id)->get();

            foreach ($products as $product) {
                // Set active=false for price list lines of products 4 and 5
                $isPriceLineActive = !(
                    strpos($product->name, 'Product 4') !== false ||
                    strpos($product->name, 'Product 5') !== false
                );

                PriceListLine::create([
                    'unit_price' => 1000 + ($categoryId * 100) + rand(1, 100),
                    'price_list_id' => $priceList->id,
                    'product_id' => $product->id,
                    'active' => $isPriceLineActive,  // Set active=false for products 4 and 5
                ]);

                // Track which products have active/inactive price list lines
                if ($isPriceLineActive) {
                    $productsWithActivePriceLines[] = $product->id;
                    Log::info("Product with active price list line", [
                        'product_id' => $product->id,
                        'product_name' => $product->name
                    ]);
                } else {
                    $productsWithInactivePriceLines[] = $product->id;
                    Log::info("Product with inactive price list line", [
                        'product_id' => $product->id,
                        'product_name' => $product->name
                    ]);
                }
            }
        }

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 5. Set current date to Friday
        $testDate = Carbon::create(2025, 5, 23, 9, 0, 0, $timezone); // Friday, May 23, 2025
        $this->travelTo($testDate);

        // 6. Create Menu
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 7. Create CategoryMenu associations - both with show_all_products true and false
        $displayOrder = 10;

        foreach ($categories as $index => $category) {
            // First category with show_all_products = true
            // Second category with show_all_products = false
            // Third category with show_all_products = true
            $showAllProducts = $index != 2;

            Log::info("Creating CategoryMenu", [
                'category_id' => $category->id,
                'show_all_products' => $showAllProducts
            ]);

            $categoryMenu = CategoryMenu::create([
                'category_id' => $category->id,
                'menu_id' => $menu->id,
                'display_order' => $displayOrder,
                'show_all_products' => $showAllProducts,
                'mandatory_category' => false,
            ]);

            $displayOrder += 10;

            // For categories with show_all_products=false, add all products to the CategoryMenu
            // including those with inactive price list lines
            if (!$showAllProducts) {
                $products = Product::where('category_id', $category->id)->get();

                foreach ($products as $product) {
                    \DB::table('category_menu_product')->insert([
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id,
                    ]);

                    Log::info("Added product to CategoryMenu", [
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id,
                        'has_active_price_line' => in_array($product->id, $productsWithActivePriceLines)
                    ]);
                }
            }
        }

        // 8. Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));

        // 9. Assert response status
        $response->assertStatus(200);

        // 10. Get the data
        $responseData = $response->json('data.data');
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);

        // 11. Verify there are 3 categories
        $this->assertCount(3, $responseData);

        // 12. Check product filtering for each CategoryMenu
        foreach ($responseData as $index => $categoryMenuData) {
            $categoryId = $categoryMenuData['category_id'];
            $showAllProducts = $categoryMenuData['show_all_products'];

            Log::info("Checking CategoryMenu in response", [
                'index' => $index,
                'category_id' => $categoryId,
                'show_all_products' => $showAllProducts,
                'category_products_count' => count($categoryMenuData['category']['products'] ?? []),
                'direct_products_count' => count($categoryMenuData['products'] ?? [])
            ]);

            if ($showAllProducts) {
                // Products should be in category.products
                $this->assertNotEmpty(
                    $categoryMenuData['category']['products'],
                    "Category {$categoryId} should have products in category.products"
                );

                // Should only have 3 products (1, 2, 3) instead of all 5, because 4 and 5 have inactive price list lines
                $this->assertCount(
                    3,
                    $categoryMenuData['category']['products'],
                    "Category {$categoryId} should have only 3 products in category.products (those with active price list lines)"
                );

                // Verify only products with active price list lines are included
                foreach ($categoryMenuData['category']['products'] as $product) {
                    $this->assertContains(
                        $product['id'],
                        $productsWithActivePriceLines,
                        "Product {$product['id']} with inactive price list line should not be included"
                    );

                    $this->assertTrue(
                        strpos($product['name'], 'Product 1') !== false ||
                            strpos($product['name'], 'Product 2') !== false ||
                            strpos($product['name'], 'Product 3') !== false,
                        "Only products 1, 2, or 3 (with active price list lines) should be included"
                    );
                }
            } else {
                // Products should be in products array
                $this->assertNotEmpty(
                    $categoryMenuData['products'],
                    "Category {$categoryId} should have products in products array"
                );

                // Should only have 3 products (1, 2, 3) instead of all 5, because 4 and 5 have inactive price list lines
                $this->assertCount(
                    3,
                    $categoryMenuData['products'],
                    "Category {$categoryId} should have only 3 products in products array (those with active price list lines)"
                );

                // Verify only products with active price list lines are included
                foreach ($categoryMenuData['products'] as $product) {
                    $this->assertContains(
                        $product['id'],
                        $productsWithActivePriceLines,
                        "Product {$product['id']} with inactive price list line should not be included"
                    );

                    $this->assertTrue(
                        strpos($product['name'], 'Product 1') !== false ||
                            strpos($product['name'], 'Product 2') !== false ||
                            strpos($product['name'], 'Product 3') !== false,
                        "Only products 1, 2, or 3 (with active price list lines) should be included"
                    );
                }
            }
        }

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that API returns empty data array when company is not associated with a price list
     */
    #[Test]
    public function category_menu_api_returns_empty_data_when_company_has_no_price_list(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create Categories and Products (this setup is the same as other tests)
        $categories = [];
        for ($i = 1; $i <= 3; $i++) {
            $categories[$i] = Category::create([
                'name' => "Test Category {$i}",
                'description' => "Description for test category {$i}",
                'is_active' => true,
            ]);

            // Create CategoryLine for each category
            CategoryLine::create([
                'category_id' => $categories[$i]->id,
                'weekday' => Weekday::FRIDAY->value,
                'preparation_days' => 1,
                'maximum_order_time' => '14:00:00',
                'active' => true
            ]);

            // Create Products for each Category
            for ($j = 1; $j <= 3; $j++) {
                Product::create([
                    'name' => "Product {$j} for Category {$i}",
                    'description' => "Description for product {$j} in category {$i}",
                    'price' => 1000 + ($i * 100) + $j,
                    'category_id' => $categories[$i]->id,
                    'code' => "PROD-{$i}-{$j}",
                    'active' => 1,
                    'measure_unit' => 'UND',
                    'price_list' => 1000 + ($i * 100) + $j,
                    'stock' => 100,
                    'weight' => '1.0',
                    'allow_sales_without_stock' => 1,
                ]);
            }
        }

        // 2. Create User, Company and associate them
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
            // Importante: NO asignamos una price_list_id
        ]);

        // Associate User with Company
        $user->company_id = $company->id;
        $user->save();

        // 3. Create PriceList but DO NOT associate it with the Company
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        // 4. Create PriceListLine for all products
        foreach ($categories as $categoryId => $category) {
            $products = Product::where('category_id', $category->id)->get();

            foreach ($products as $product) {
                PriceListLine::create([
                    'unit_price' => 1000 + ($categoryId * 100) + rand(1, 100),
                    'price_list_id' => $priceList->id,
                    'product_id' => $product->id,
                    'active' => true,
                ]);
            }
        }

        // Create auth token for testing
        $token = $user->createToken('test-token')->plainTextToken;

        // 5. Set current date to Friday
        $testDate = Carbon::create(2025, 5, 23, 9, 0, 0, $timezone); // Friday, May 23, 2025
        $this->travelTo($testDate);

        // 6. Create Menu
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'active' => true,
        ]);

        // 7. Create CategoryMenu associations
        $displayOrder = 10;

        foreach ($categories as $index => $category) {
            $showAllProducts = $index % 2 == 0;

            $categoryMenu = CategoryMenu::create([
                'category_id' => $category->id,
                'menu_id' => $menu->id,
                'display_order' => $displayOrder,
                'show_all_products' => $showAllProducts,
                'mandatory_category' => false,
            ]);

            $displayOrder += 10;

            // For categories with show_all_products=false, add specific products
            if (!$showAllProducts) {
                $products = Product::where('category_id', $category->id)->get();

                foreach ($products as $product) {
                    \DB::table('category_menu_product')->insert([
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id,
                    ]);
                }
            }
        }

        // 8. Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.categories.show', $menu->id));

        // 9. Assert response status is still 200 (success)
        $response->assertStatus(200);

        // 10. Get the data
        $responseData = $response->json('data.data');

        // 11. Verify that data.data is an empty array
        // This is the key test assertion: when the company has no price list, 
        // the response should have an empty data array
        $this->assertIsArray($responseData);
        $this->assertEmpty($responseData, 'The data array should be empty when company has no price list');

        // 12. Additional verification: check that the overall response structure is still correct
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'current_page',
                'data',
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

        // Check that total count is 0
        $this->assertEquals(
            0,
            $response->json('data.total'),
            'The total count should be 0 when company has no price list'
        );

        // Return to the present
        $this->travelBack();
    }

    /**
     * Test that API returns 401 when user does not have the correct permissions
     */
    #[Test]
    public function category_menu_api_returns_403_when_user_has_insufficient_permissions(): void
    {
        // Set timezone and locale for consistent date formatting
        $timezone = config('app.timezone', 'UTC');
        Carbon::setLocale(config('app.locale'));

        // 1. Create Categories and Products
        $categories = [];
        for ($i = 1; $i <= 3; $i++) {
            $categories[$i] = Category::create([
                'name' => "Test Category {$i}",
                'description' => "Description for test category {$i}",
                'is_active' => true,
            ]);

            // Create CategoryLine for each category
            CategoryLine::create([
                'category_id' => $categories[$i]->id,
                'weekday' => Weekday::FRIDAY->value,
                'preparation_days' => 1,
                'maximum_order_time' => '14:00:00',
                'active' => true
            ]);

            // Create Products for each Category
            for ($j = 1; $j <= 3; $j++) {
                Product::create([
                    'name' => "Product {$j} for Category {$i}",
                    'description' => "Description for product {$j} in category {$i}",
                    'price' => 1000 + ($i * 100) + $j,
                    'category_id' => $categories[$i]->id,
                    'code' => "PROD-{$i}-{$j}",
                    'active' => 1,
                    'measure_unit' => 'UND',
                    'price_list' => 1000 + ($i * 100) + $j,
                    'stock' => 100,
                    'weight' => '1.0',
                    'allow_sales_without_stock' => 1,
                ]);
            }
        }

        // 2. Create Roles and Permissions
        $convenioRole = Role::create(['name' => RoleName::AGREEMENT->value]);
        $individualRole = Role::create(['name' => RoleName::CAFE->value]);

        $consolidadoPermission = Permission::create(['name' => PermissionName::CONSOLIDADO->value]);
        $individualPermission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        // 3. Create PriceList
        $priceList = PriceList::create([
            'name' => 'Test Price List',
            'description' => 'Test price list description',
            'min_price_order' => 0,
        ]);

        // 4. Create Companies
        $company1 = Company::create([
            'name' => 'Test Company 1',
            'fantasy_name' => 'Test Company 1',
            'address' => 'Test Address 1',
            'email' => 'test1@example.com',
            'phone_number' => '123456789',
            'registration_number' => 'REG123456',
            'description' => 'Test company description',
            'active' => true,
            'tax_id' => '123456789',
            'price_list_id' => $priceList->id,
        ]);

        $company2 = Company::create([
            'name' => 'Test Company 2',
            'fantasy_name' => 'Test Company 2',
            'address' => 'Test Address 2',
            'email' => 'test2@example.com',
            'phone_number' => '987654321',
            'registration_number' => 'REG654321',
            'description' => 'Test company description',
            'active' => true,
            'tax_id' => '987654321',
            'price_list_id' => $priceList->id,
        ]);

        // 5. Create Users - one with correct permissions, one with incorrect permissions
        $user1 = User::factory()->create([
            'name' => 'Authorized User',
            'email' => 'authorized@example.com',
            'allow_late_orders' => true,
            'company_id' => $company1->id,
        ]);

        $user2 = User::factory()->create([
            'name' => 'Unauthorized User',
            'email' => 'unauthorized@example.com',
            'allow_late_orders' => true,
            'company_id' => $company2->id,
        ]);

        // Assign roles and permissions
        // User 1 - Correct permissions: 'Convenio' role + 'Consolidado' permission
        $user1->roles()->attach($convenioRole);
        $user1->permissions()->attach($consolidadoPermission);

        // User 2 - Incorrect permissions: 'Convenio' role + 'Individual' permission
        $user2->roles()->attach($convenioRole);
        $user2->permissions()->attach($individualPermission);

        // 6. Create PriceListLine for all products
        foreach ($categories as $categoryId => $category) {
            $products = Product::where('category_id', $category->id)->get();

            foreach ($products as $product) {
                PriceListLine::create([
                    'unit_price' => 1000 + ($categoryId * 100) + rand(1, 100),
                    'price_list_id' => $priceList->id,
                    'product_id' => $product->id,
                    'active' => true,
                ]);
            }
        }

        // 8. Set current date to Friday
        $testDate = Carbon::create(2025, 5, 23, 9, 0, 0, $timezone); // Friday, May 23, 2025
        $this->travelTo($testDate);

        // 9. Create Menu with specific role and permission requirements
        $menu = Menu::create([
            'title' => "Menu for {$testDate->format('F j, Y')}",
            'description' => 'Test menu description',
            'publication_date' => $testDate->format('Y-m-d'),
            'max_order_date' => $testDate->format('Y-m-d') . ' 14:00:00',
            'role_id' => $convenioRole->id,           // Requires 'Convenio' role
            'permissions_id' => $consolidadoPermission->id,  // Requires 'Consolidado' permission
            'active' => true,
        ]);

        // 10. Create CategoryMenu associations
        $displayOrder = 10;

        foreach ($categories as $index => $category) {
            $showAllProducts = $index % 2 == 0;

            $categoryMenu = CategoryMenu::create([
                'category_id' => $category->id,
                'menu_id' => $menu->id,
                'display_order' => $displayOrder,
                'show_all_products' => $showAllProducts,
                'mandatory_category' => false,
            ]);

            $displayOrder += 10;

            // For categories with show_all_products=false, add specific products
            if (!$showAllProducts) {
                $products = Product::where('category_id', $category->id)->get();

                foreach ($products as $product) {
                    \DB::table('category_menu_product')->insert([
                        'category_menu_id' => $categoryMenu->id,
                        'product_id' => $product->id,
                    ]);
                }
            }
        }

        // 11. Test with authorized user (should return 200)
        // Usando actingAs en lugar de withToken
        $this->actingAs($user1, 'sanctum');
        $response1 = $this->getJson(route('v1.categories.show', $menu->id));

        $response1->assertStatus(200);
        $this->assertNotEmpty($response1->json('data.data'), 'Authorized user should get data');

        Log::info("Response from authorized user:", [
            'status' => $response1->status(),
            'data_count' => count($response1->json('data.data') ?? []),
        ]);
        
        // 12. Test with unauthorized user (should return 403)
        // Usando actingAs en lugar de withToken
        $this->actingAs($user2, 'sanctum');
        $response2 = $this->getJson(route('v1.categories.show', $menu->id));

        Log::info("Response from unauthorized user:", [
            'status' => $response2->status(),
            'content' => $response2->content(),
        ]);

        // Key assertion: user with incorrect permissions gets 403
        $response2->assertStatus(403);

        // Additional verification: check error message
        $response2->assertJson([
            'status' => "error",
            'message' => "This action is unauthorized."
        ]);

        // Return to the present
        $this->travelBack();
    }
}
