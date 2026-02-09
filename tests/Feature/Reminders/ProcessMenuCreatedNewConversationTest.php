<?php

namespace Tests\Feature\Reminders;

use App\Enums\CampaignEventType;
use App\Enums\CampaignExecutionStatus;
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
use App\Models\Integration;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\User;
use App\Services\Reminders\ProcessRemindersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test: New conversation flow for menu_created event.
 *
 * SCENARIO:
 * A menu is recently created, there is an active reminder campaign
 * configured for the menu_created event, and the recipient has NO
 * prior WhatsApp conversation. The system should:
 * 1. Detect the menu as eligible
 * 2. Create a new conversation (which triggers the WhatsApp template)
 * 3. Record the notification as "pending" (waiting for user response)
 * 4. Store the pending notification with the message content
 * 5. Register the campaign execution
 * 6. Update the trigger's last_executed_at
 *
 * EXPECTED RESULT:
 * - processEventType() returns: triggers_processed=1, pending=1, sent=0
 * - 1 record in reminder_notified_menus with status=pending
 * - 1 record in reminder_pending_notifications with status=waiting_response
 * - 1 record in campaign_executions
 * - 1 conversation created
 */
class ProcessMenuCreatedNewConversationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Branch $branch;
    private Role $role;
    private Permission $permission;
    private Campaign $campaign;
    private CampaignTrigger $trigger;
    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        config(['reminders.test_mode' => true]);
        config(['whatsapp.phone_number_id' => 'FAKE_PHONE_ID']);
        config(['whatsapp.api_token' => 'fake_token']);
        config(['whatsapp.initial_template_name' => 'hello_world']);

        $testPhone = config('whatsapp.test_phone_number');

        Http::fake([
            '*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+' . $testPhone, 'wa_id' => $testPhone]],
                'messages' => [['id' => 'wamid.fake123']],
            ], 200),
        ]);

        $this->createWhatsAppIntegration();
        $this->createRoleAndPermission();
        $this->createCompanyWithBranch();
        $this->createCampaignWithTrigger();
        $this->createEligibleMenu();
    }

    private function createWhatsAppIntegration(): void
    {
        Integration::create([
            'name' => IntegrationName::WHATSAPP,
            'url' => 'https://graph.facebook.com/v24.0',
            'url_test' => 'https://graph.facebook.com/v24.0',
            'type' => IntegrationType::MESSAGING,
            'production' => false,
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
            'name' => 'TEST REMINDER COMPANY S.A.',
            'email' => 'reminder-company@test.com',
            'tax_id' => '11.111.111-1',
            'company_code' => 'TESTREM',
            'fantasy_name' => 'TEST REMINDER CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '0000000000',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Test Address 123',
            'contact_name' => 'Test Contact',
            'contact_phone_number' => '0000000001',
            'branch_code' => 'BR001',
            'fantasy_name' => 'Test Branch',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'TEST REMINDER USER',
            'nickname' => 'TEST.REMINDER.USER',
            'email' => 'reminder-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);
        $user->roles()->attach($this->role->id);
        $user->permissions()->attach($this->permission->id);
    }

    private function createCampaignWithTrigger(): void
    {
        $this->campaign = Campaign::create([
            'name' => 'Test Menu Created Reminder',
            'type' => CampaignType::REMINDER->value,
            'channel' => 'whatsapp',
            'status' => CampaignStatus::ACTIVE->value,
            'content' => 'Hay {{menu_count}} nuevos menus: {{menus}}',
        ]);

        $this->campaign->companies()->attach($this->company->id);

        $this->trigger = CampaignTrigger::create([
            'campaign_id' => $this->campaign->id,
            'trigger_type' => TriggerType::EVENT->value,
            'event_type' => CampaignEventType::MENU_CREATED->value,
            'hours_after' => 0,
            'is_active' => true,
        ]);
    }

    private function createEligibleMenu(): void
    {
        $this->menu = Menu::create([
            'title' => 'Menu Test Lunes',
            'description' => 'Menu de prueba',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => now()->toDateString(),
            'max_order_date' => now()->addDays(1)->toDateTimeString(),
        ]);
    }

    // =========================================================================
    // TEST
    // =========================================================================

    public function test_new_conversation_flow_creates_pending_notification(): void
    {
        $testPhone = config('whatsapp.test_phone_number');

        /** @var ProcessRemindersService $service */
        $service = app(ProcessRemindersService::class);

        $result = $service->processEventType(CampaignEventType::MENU_CREATED);

        // A. Service returns correct totals
        $this->assertEquals(1, $result['triggers_processed']);
        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(1, $result['pending']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);

        // B. Conversation was created for the recipient
        $this->assertDatabaseHas('conversations', [
            'phone_number' => $testPhone,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        // C. Template message was sent via ConversationObserver
        $this->assertDatabaseHas('messages', [
            'direction' => 'outbound',
            'type' => 'template',
            'body' => 'hello_world',
        ]);

        // D. Notification recorded as pending in reminder_notified_menus
        $this->assertDatabaseHas('reminder_notified_menus', [
            'trigger_id' => $this->trigger->id,
            'menu_id' => $this->menu->id,
            'phone_number' => $testPhone,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('reminder_notified_menus', [
            'status' => 'sent',
        ]);

        // E. Pending notification stored with message content
        $this->assertDatabaseHas('reminder_pending_notifications', [
            'trigger_id' => $this->trigger->id,
            'phone_number' => $testPhone,
            'message_content' => 'Hay 1 nuevos menus: Menu Test Lunes',
            'status' => 'waiting_response',
        ]);

        // F. Campaign execution recorded
        $this->assertDatabaseHas('campaign_executions', [
            'campaign_id' => $this->campaign->id,
            'trigger_id' => $this->trigger->id,
            'total_recipients' => 1,
            'sent_count' => 0,
            'failed_count' => 0,
            'status' => CampaignExecutionStatus::COMPLETED->value,
        ]);

        // G. Trigger last_executed_at updated
        $this->trigger->refresh();
        $this->assertNotNull($this->trigger->last_executed_at);
    }

    public function test_second_execution_skips_already_pending_recipient(): void
    {
        /** @var ProcessRemindersService $service */
        $service = app(ProcessRemindersService::class);

        // First execution: creates pending notification
        $service->processEventType(CampaignEventType::MENU_CREATED);

        // Second execution: same menu, same recipient → should skip
        $result = $service->processEventType(CampaignEventType::MENU_CREATED);

        $this->assertEquals(1, $result['triggers_processed']);
        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(0, $result['pending']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(1, $result['skipped']);

        // Only 1 record in reminder_notified_menus (not duplicated)
        $this->assertEquals(1, \App\Models\ReminderNotifiedMenu::count());
    }
}