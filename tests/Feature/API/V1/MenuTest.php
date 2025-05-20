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
}
