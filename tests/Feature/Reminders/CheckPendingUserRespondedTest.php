<?php

namespace Tests\Feature\Reminders;

use App\Actions\Conversations\CreateConversationMessageAction;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test 1: User responded to template → check-pending sends the reminder.
 *
 * SCENARIO:
 * 1. reminders:process creates conversation + template + pending notification
 * 2. User responds (inbound message)
 * 3. reminders:check-pending detects the response and sends the reminder message
 *
 * EXPECTED:
 * - Reminder message sent as outbound text
 * - reminder_pending_notifications.status = 'sent'
 * - reminder_notified_menus.status = 'sent' with notified_at
 */
class CheckPendingUserRespondedTest extends TestCase
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
            'name' => 'CHECK PENDING COMPANY S.A.',
            'email' => 'check-pending@test.com',
            'tax_id' => '11.111.111-1',
            'company_code' => 'CHKPND',
            'fantasy_name' => 'CHECK PENDING CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '560000000099',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Test Address 123',
            'contact_name' => 'Test Contact',
            'contact_phone_number' => '560000000088',
            'branch_code' => 'CP01',
            'fantasy_name' => 'Check Pending Branch',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'CHECK PENDING USER',
            'nickname' => 'TEST.CHKPND.USER',
            'email' => 'chkpnd-user@test.com',
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
            'name' => 'Test Check Pending Reminder',
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

    public function test_sends_reminder_when_user_has_responded(): void
    {
        // PHASE 1: Execute reminders:process command
        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        // Verify preconditions created by the command
        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(1, ReminderPendingNotification::where('status', 'waiting_response')->count());
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'pending')->count());

        // PHASE 2: Simulate user responding to template (inbound message)
        $conversation = Conversation::first();
        CreateConversationMessageAction::execute([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Hola, si quiero ver los menus',
        ]);

        // PHASE 3: Execute reminders:check-pending command
        $this->artisan('reminders:check-pending')
            ->assertSuccessful();

        // A. Reminder message sent as outbound text
        $reminderMessage = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('type', 'text')
            ->first();

        $this->assertNotNull($reminderMessage);
        $this->assertEquals('Hay 3 nuevos menus: Menu Lunes, Menu Martes, Menu Miercoles', $reminderMessage->body);

        // Total messages: template (outbound) + user response (inbound) + reminder (outbound)
        $this->assertEquals(3, Message::where('conversation_id', $conversation->id)->count());

        // B. Pending notification marked as sent
        $this->assertEquals(0, ReminderPendingNotification::where('status', 'waiting_response')->count());
        $this->assertEquals(1, ReminderPendingNotification::where('status', 'sent')->count());

        // C. All notified menus marked as sent with notified_at
        $this->assertEquals(0, ReminderNotifiedMenu::where('status', 'pending')->count());
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'sent')->count());

        foreach (ReminderNotifiedMenu::all() as $notified) {
            $this->assertNotNull($notified->notified_at);
        }

        // NEGATIVE VALIDATIONS
        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(1, ReminderPendingNotification::count());
        $this->assertEquals(3, ReminderNotifiedMenu::count());
    }
}