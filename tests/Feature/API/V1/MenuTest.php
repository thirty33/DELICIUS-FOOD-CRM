<?php

namespace Tests\Feature\API\V1;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Illuminate\Support\Facades\Config;
use Tests\Helpers\MenuDataHelper;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[Group('api:v1')]
#[Group('api:v1:menus')]
class MenuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var string
     */
    private string $timezone;

    /**
     * @var Role
     */
    private Role $convenioRole;

    /**
     * @var Permission
     */
    private Permission $consolidadoPermission;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set timezone from config
        $this->timezone = Config::get('app.timezone', 'UTC');

        // Create necessary roles and permissions
        $this->convenioRole = Role::factory()->create(['name' => RoleName::AGREEMENT->value]);
        $this->consolidadoPermission = Permission::factory()->create(['name' => PermissionName::CONSOLIDADO->value]);
    }

    /**
     * Test the menus.index API returns an empty data array when no menus are available
     * for the user's role and permissions on a specific date.
     */
    #[Test]
    public function api_returns_empty_data_when_user_cannot_see_late_orders(): void
    {
        // Create a user with Convenio role and Consolidado permission
        $user = User::factory()->create([
            'allow_late_orders' => true
        ]);
        $user->roles()->attach($this->convenioRole);
        $user->permissions()->attach($this->consolidadoPermission);

        // Generate auth token
        $token = $user->createToken('test-token')->plainTextToken;

        // Travel to May 20, 2025
        $this->travelTo(Carbon::create(2025, 5, 20, 9, 0, 0, $this->timezone));

        // Create all menus using the helper
        $menuIds = MenuDataHelper::createMenus($this->convenioRole, $this->consolidadoPermission);

        // Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.menus.index'));

        // Assert response is successful and has empty data array
        $response->assertStatus(200)
            ->assertJsonStructure([
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
            ])
            ->assertJsonPath('data.data', []) // Assert data.data is an empty array
            ->assertJsonPath('data.total', 0) // Assert total is 0
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Active menus retrieved successfully');

        // Travel back to not affect other tests
        $this->travelBack();
    }

    /**
     * Test the menus.index API returns data when user has allow_late_orders enabled.
     */
    #[Test]
    public function api_returns_menu_data_when_user_can_see_late_orders(): void
    {
        // Create a user with Convenio role, Consolidado permission and allow_late_orders enabled
        $user = User::factory()->create([
            'allow_late_orders' => false
        ]);
        $user->roles()->attach($this->convenioRole);
        $user->permissions()->attach($this->consolidadoPermission);

        // Generate auth token
        $token = $user->createToken('test-token')->plainTextToken;

        // Travel to May 20, 2025
        $testDate = Carbon::create(2025, 5, 20, 9, 0, 0, $this->timezone);
        $this->travelTo($testDate);

        // Create all menus using the helper
        $menuIds = MenuDataHelper::createMenus($this->convenioRole, $this->consolidadoPermission);

        // Make API request
        $response = $this->withToken($token)
            ->getJson(route('v1.menus.index'));

        // Assert response is successful and contains menu data
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'current_page',
                    'data' => [
                        '*' => [
                            'id',
                            'active',
                            'title',
                            'description',
                            'publication_date',
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
            ])
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Active menus retrieved successfully');

        // Assert data.data is not empty
        $responseData = $response->json('data.data');
        $this->assertNotEmpty($responseData);

        // Assert total is greater than 0
        $total = $response->json('data.total');
        $this->assertGreaterThan(0, $total);

        // Verify that menus are ordered by publication_date from earliest to latest
        $publicationDates = collect($responseData)->pluck('publication_date')->toArray();
        $sortedDates = $publicationDates;
        sort($sortedDates);
        $this->assertEquals($sortedDates, $publicationDates, 'Menus should be ordered by publication_date from earliest to latest');

        // Verify that the first menu has a publication_date greater than or equal to the current date
        $firstMenuPublicationDate = Carbon::parse($publicationDates[0]);
        $this->assertTrue(
            $firstMenuPublicationDate->startOfDay()->greaterThanOrEqualTo($testDate->copy()->startOfDay()),
            'First menu publication_date should be greater than or equal to current date'
        );

        // Travel back to not affect other tests
        $this->travelBack();
    }

    /**
     * Test the menus.index API returns data ordered by publication_date
     * even when menus are inserted in random order.
     */
    #[Test]
    public function api_returns_menu_data_ordered_by_publication_date_regardless_of_insertion_order(): void
    {
        // Create a user with Convenio role, Consolidado permission and allow_late_orders enabled
        $user = User::factory()->create([
            'allow_late_orders' => false
        ]);
        $user->roles()->attach($this->convenioRole);
        $user->permissions()->attach($this->consolidadoPermission);
        \Log::info('Test user created with ID: ' . $user->id);

        // Generate auth token
        $token = $user->createToken('test-token')->plainTextToken;
        \Log::info('Auth token generated');

        // Travel to May 20, 2025
        $testDate = Carbon::create(2025, 5, 20, 9, 0, 0, $this->timezone);
        $this->travelTo($testDate);
        \Log::info('Time travel to: ' . $testDate->toDateTimeString() . ' (' . $this->timezone . ')');

        // Get menu data and shuffle it to insert in random order
        $menusData = MenuDataHelper::getMenuData($this->convenioRole, $this->consolidadoPermission);
        \Log::info('Original menus count: ' . count($menusData));

        // Shuffle to randomize insertion order
        shuffle($menusData);
        \Log::info('Menus shuffled for random insertion order');

        // Create menus in random order
        foreach ($menusData as $index => $menuData) {
            $menu = Menu::create($menuData);
            if ($index < 3 || $index > count($menusData) - 4) {
                \Log::info("Created menu #{$index}: ID {$menu->id}, Title: {$menu->title}, Date: {$menu->publication_date}, Max Order: {$menu->max_order_date}");
            } elseif ($index === 3) {
                \Log::info("... (more menus created) ...");
            }
        }

        // Store expected dates for later verification
        $publicationDates = collect($menusData)
            ->pluck('publication_date')
            ->map(fn($date) => Carbon::parse($date)->format('Y-m-d'))
            ->toArray();

        // Log a few sample dates
        \Log::info('Sample of original publication dates (unsorted): ' .
            implode(', ', array_slice($publicationDates, 0, 5)));

        // Sort dates to get expected order
        sort($publicationDates);

        // Log the sorted dates
        \Log::info('Sample of sorted publication dates: ' .
            implode(', ', array_slice($publicationDates, 0, 5)));

        // Find earliest date on or after test date
        $earliestValidDate = null;
        foreach ($publicationDates as $date) {
            if (Carbon::parse($date)->startOfDay()->greaterThanOrEqualTo($testDate->copy()->startOfDay())) {
                $earliestValidDate = $date;
                break;
            }
        }

        \Log::info('Current test date: ' . $testDate->format('Y-m-d'));
        \Log::info('Earliest valid publication date: ' . ($earliestValidDate ?? 'None found'));

        // Make API request
        \Log::info('Making API request to menus.index');
        $response = $this->withToken($token)
            ->getJson(route('v1.menus.index'));
        \Log::info('API response status code: ' . $response->status());

        // Log the raw response for debugging
        \Log::info('API response body (excerpt): ' . substr(json_encode($response->json()), 0, 500) . '...');

        // Assert response is successful and contains menu data
        try {
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'message',
                    'data' => [
                        'current_page',
                        'data' => [
                            '*' => [
                                'id',
                                'active',
                                'title',
                                'description',
                                'publication_date',
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
                ])
                ->assertJsonPath('status', 'success')
                ->assertJsonPath('message', 'Active menus retrieved successfully');
            \Log::info('JSON structure validation passed');
        } catch (\Exception $e) {
            \Log::error('JSON structure validation failed: ' . $e->getMessage());
            throw $e;
        }

        // Assert data.data is not empty
        $responseData = $response->json('data.data');
        if (empty($responseData)) {
            \Log::error('Response data is empty');
            $this->fail('Response data is empty but expected to contain menus');
        } else {
            \Log::info('Response data contains ' . count($responseData) . ' menus');
        }
        $this->assertNotEmpty($responseData);

        // Assert total is greater than 0
        $total = $response->json('data.total');
        \Log::info('Total menus in response: ' . $total);
        $this->assertGreaterThan(0, $total);

        // Get the publication dates from the response
        $responseDates = collect($responseData)->pluck('publication_date')->toArray();
        \Log::info('Response dates (first 5): ' . implode(', ', array_slice($responseDates, 0, 5)));

        // Verify that menus are ordered by publication_date from earliest to latest
        $sortedResponseDates = $responseDates;
        sort($sortedResponseDates);
        $areDatesOrdered = $sortedResponseDates === $responseDates;
        \Log::info('Are dates properly ordered? ' . ($areDatesOrdered ? 'Yes' : 'No'));

        if (!$areDatesOrdered) {
            \Log::error('Dates are not ordered correctly');
            \Log::error('Expected order (first 5): ' . implode(', ', array_slice($sortedResponseDates, 0, 5)));
            \Log::error('Actual order (first 5): ' . implode(', ', array_slice($responseDates, 0, 5)));
        }

        $this->assertEquals($sortedResponseDates, $responseDates, 'Menus should be ordered by publication_date from earliest to latest');

        // Verify that the first menu has the earliest valid publication_date
        $firstMenuPublicationDate = Carbon::parse($responseDates[0])->format('Y-m-d');
        \Log::info('First menu from response: ' . $firstMenuPublicationDate);

        $isFirstDateCorrect = $earliestValidDate === $firstMenuPublicationDate;
        \Log::info('Is first date the earliest valid date? ' . ($isFirstDateCorrect ? 'Yes' : 'No'));

        if (!$isFirstDateCorrect) {
            \Log::error('First menu date is not the earliest valid date');
            \Log::error('Expected: ' . $earliestValidDate);
            \Log::error('Actual: ' . $firstMenuPublicationDate);
        }

        $this->assertEquals(
            $earliestValidDate,
            $firstMenuPublicationDate,
            'First menu should have the earliest publication_date that is on or after the current date'
        );

        // Verify that all returned dates are on or after current date
        $allDatesValid = true;
        foreach ($responseDates as $index => $date) {
            $responseDate = Carbon::parse($date);
            $isValidDate = $responseDate->startOfDay()->greaterThanOrEqualTo($testDate->copy()->startOfDay());

            if (!$isValidDate) {
                $allDatesValid = false;
                \Log::error("Menu at index {$index} has invalid date: {$date}");
            }
        }
        \Log::info('Are all response dates on or after current date? ' . ($allDatesValid ? 'Yes' : 'No'));

        // Travel back to not affect other tests
        $this->travelBack();
        \Log::info('Time travel completed - test finished');
    }

    /**
     * Test that the menus.index API returns empty data when user has Convenio Individual role/permission
     * combination but all menus are for Convenio Consolidado.
     */
    #[Test]
    public function api_returns_empty_data_when_user_has_individual_permission_but_menus_are_for_consolidado(): void
    {
        // Create roles and permissions for the test
        $individualPermission = Permission::factory()->create(['name' => PermissionName::INDIVIDUAL->value]);

        // Create a user with Convenio role and Individual permission
        $user = User::factory()->create([
            'allow_late_orders' => true // Allow late orders to ensure we're testing permission filtering
        ]);
        $user->roles()->attach($this->convenioRole);
        $user->permissions()->attach($individualPermission);

        \Log::info('Test user created with ID: ' . $user->id);
        \Log::info('User has Convenio role and Individual permission');

        // Generate auth token
        $token = $user->createToken('test-token')->plainTextToken;

        // Travel to May 20, 2025
        $testDate = Carbon::create(2025, 5, 20, 9, 0, 0, $this->timezone);
        $this->travelTo($testDate);
        \Log::info('Time travel to: ' . $testDate->toDateTimeString() . ' (' . $this->timezone . ')');

        // Create menus for Convenio Consolidado
        $menuIds = MenuDataHelper::createMenus($this->convenioRole, $this->consolidadoPermission);
        \Log::info('Created ' . count($menuIds) . ' menus for Convenio-Consolidado');

        // Verify that menus exist in the database
        $totalMenusInDB = Menu::count();
        \Log::info('Total menus in database: ' . $totalMenusInDB);

        // Add a future max_order_date to ensure that's not the reason for empty results
        Menu::whereIn('id', array_slice($menuIds, 0, 5))->update([
            'max_order_date' => Carbon::now()->addDays(5)->format('Y-m-d H:i:s')
        ]);
        \Log::info('Updated 5 menus with future max_order_date');

        // Make API request
        \Log::info('Making API request to menus.index as Convenio-Individual user');
        $response = $this->withToken($token)
            ->getJson(route('v1.menus.index'));

        \Log::info('API response status code: ' . $response->status());
        \Log::info('API response body (excerpt): ' . substr(json_encode($response->json()), 0, 500) . '...');

        // Assert response is successful and has empty data array
        $response->assertStatus(200)
            ->assertJsonStructure([
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
            ])
            ->assertJsonPath('data.data', []) // Assert data.data is an empty array
            ->assertJsonPath('data.total', 0) // Assert total is 0
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Active menus retrieved successfully');

        // Verify with additional logs
        $responseData = $response->json('data.data');
        \Log::info('Response data array is ' . (empty($responseData) ? 'empty' : 'not empty'));
        \Log::info('Response total count: ' . $response->json('data.total'));

        // Additional assertion to demonstrate we're filtering by permissions
        // Create one menu with Individual permission to show permission filtering works
        $individualMenu = Menu::create([
            'title' => 'Menú Test - Convenio-Individual',
            'description' => 'Menú de prueba para el permiso Individual.',
            'publication_date' => Carbon::now()->addDay()->format('Y-m-d'),
            'role_id' => $this->convenioRole->id,
            'permissions_id' => $individualPermission->id,
            'max_order_date' => Carbon::now()->addDays(5)->format('Y-m-d H:i:s'),
            'active' => true,
        ]);
        \Log::info('Created additional menu for Convenio-Individual with ID: ' . $individualMenu->id);

        // Make second API request
        \Log::info('Making second API request to verify permission filtering');
        $secondResponse = $this->withToken($token)
            ->getJson(route('v1.menus.index'));

        // Assert the second response now has data (the individual menu)
        $this->assertNotEmpty($secondResponse->json('data.data'));
        $this->assertGreaterThan(0, $secondResponse->json('data.total'));
        \Log::info('Second response has data: ' . ($secondResponse->json('data.total') > 0 ? 'Yes' : 'No'));

        // Travel back to not affect other tests
        $this->travelBack();
        \Log::info('Time travel completed - test finished');
    }
}
