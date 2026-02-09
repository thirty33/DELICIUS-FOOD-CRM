<?php

namespace Tests\Feature\Reminders;

use App\Enums\CampaignEventType;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\IntegrationName;
use App\Enums\IntegrationType;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TriggerType;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Menu;
use App\Models\Message;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\ReminderNotifiedMenu;
use App\Models\ReminderPendingNotification;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test 2: No response and expired → mark as expired.
 *
 * SCENARIO:
 * 1. reminders:process creates conversation + template + pending notification
 * 2. User does NOT respond
 * 3. Time passes beyond pending_expiration_hours
 * 4. reminders:check-pending detects expiration and marks as expired/failed
 *
 * EXPECTED:
 * - No reminder message sent
 * - reminder_pending_notifications.status = 'expired'
 * - reminder_notified_menus.status = 'failed'
 */
class CheckPendingExpiredTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Branch $branch;
    private Role $role;
    private Permission $permission;
    private Campaign $campaign;
    private CampaignTrigger $trigger;
    private array $menus = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        config(['reminders.test_mode' => true]);
        config(['reminders.pending_expiration_hours' => 48]);
        config(['whatsapp.phone_number_id' => 'FAKE_PHONE_ID']);
        config(['whatsapp.api_token' => 'fake_token']);
        config(['whatsapp.initial_template_name' => 'hello_world']);

        Http::fake([
            '*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+0000000000', 'wa_id' => '0000000000']],
                'messages' => [['id' => 'wamid.fake_' . uniqid()]],
            ], 200),
        ]);

        $this->createWhatsAppIntegration();
        $this->createRoleAndPermission();
        $this->createCompanyWithBranch();
        $this->createCampaignAndTrigger();
        $this->createMenus();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createWhatsAppIntegration(): void
    {
        Integration::create([
            'name' => IntegrationName::WHATSAPP,
            'url' => 'https://graph.facebook.com/v24.0',
            'url_test' => 'https://graph.facebook.com/v24.0',
            'type' => IntegrationType::MESSAGING,
            'production' => true,
            'active' => true,
        ]);
    }

    private function createRoleAndPermission(): void
    {
        $this->role = Role::create(['name' => RoleName::AGREEMENT->value]);
        $this->permission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);
    }

    private function createCompanyWithBranch(): void
    {
        $priceList = PriceList::create(['name' => 'Test Price List']);

        $this->company = Company::create([
            'name' => 'EXPIRED PENDING COMPANY S.A.',
            'email' => 'expired-pending@test.com',
            'tax_id' => '11.111.111-2',
            'company_code' => 'EXPPND',
            'fantasy_name' => 'EXPIRED PENDING CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '560000000077',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Test Address 456',
            'contact_name' => 'Test Contact',
            'contact_phone_number' => '560000000066',
            'branch_code' => 'EP01',
            'fantasy_name' => 'Expired Pending Branch',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'EXPIRED PENDING USER',
            'nickname' => 'TEST.EXPPND.USER',
            'email' => 'exppnd-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);
        $user->roles()->attach($this->role->id);
        $user->permissions()->attach($this->permission->id);
    }

    private function createCampaignAndTrigger(): void
    {
        $this->campaign = Campaign::create([
            'name' => 'Test Expired Pending Reminder',
            'type' => CampaignType::REMINDER->value,
            'channel' => 'whatsapp',
            'status' => CampaignStatus::ACTIVE->value,
            'content' => 'Hay {{menu_count}} nuevos menus: {{menus}}',
        ]);

        $this->campaign->companies()->attach($this->company->id);
        $this->campaign->branches()->attach($this->branch->id);

        $this->trigger = CampaignTrigger::create([
            'campaign_id' => $this->campaign->id,
            'trigger_type' => TriggerType::EVENT->value,
            'event_type' => CampaignEventType::MENU_CREATED->value,
            'hours_after' => 0,
            'is_active' => true,
        ]);
    }

    private function createMenus(): void
    {
        $days = ['Lunes', 'Martes', 'Miercoles'];

        foreach ($days as $i => $day) {
            $this->menus[] = Menu::create([
                'title' => "Menu {$day}",
                'description' => "Menu de {$day}",
                'active' => true,
                'role_id' => $this->role->id,
                'permissions_id' => $this->permission->id,
                'publication_date' => now()->addDays($i)->toDateString(),
                'max_order_date' => now()->addDays($i + 1)->toDateTimeString(),
            ]);
        }
    }

    // =========================================================================
    // TEST
    // =========================================================================

    public function test_marks_as_expired_when_no_response_after_timeout(): void
    {
        // PHASE 1: Execute reminders:process command
        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        // Verify preconditions created by the command
        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(1, ReminderPendingNotification::where('status', 'waiting_response')->count());
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'pending')->count());

        $conversation = Conversation::first();
        $messageCountBefore = Message::where('conversation_id', $conversation->id)->count();

        // PHASE 2: Advance time past expiration (49 hours > 48 configured)
        Carbon::setTestNow(now()->addHours(49));

        // PHASE 3: Execute reminders:check-pending command
        $this->artisan('reminders:check-pending')
            ->assertSuccessful();

        // A. Pending notification marked as expired
        $this->assertEquals(0, ReminderPendingNotification::where('status', 'waiting_response')->count());
        $this->assertEquals(1, ReminderPendingNotification::where('status', 'expired')->count());

        // B. All notified menus marked as failed
        $this->assertEquals(0, ReminderNotifiedMenu::where('status', 'pending')->count());
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'failed')->count());

        // notified_at remains null (never sent)
        foreach (ReminderNotifiedMenu::all() as $notified) {
            $this->assertNull($notified->notified_at);
        }

        // C. No new messages sent
        $messageCountAfter = Message::where('conversation_id', $conversation->id)->count();
        $this->assertEquals($messageCountBefore, $messageCountAfter);

        // NEGATIVE VALIDATIONS
        $this->assertEquals(1, Conversation::count());
        $this->assertDatabaseMissing('reminder_pending_notifications', ['status' => 'sent']);
        $this->assertDatabaseMissing('reminder_notified_menus', ['status' => 'sent']);
    }
}
