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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-End Test 1: Full flow without prior conversation.
 *
 * FLOW:
 * 1. reminders:process → creates conversation + template + pending
 * 2. User responds (webhook simulation)
 * 3. reminders:check-pending → sends reminder with menu list
 */
class EndToEndNewConversationTest extends TestCase
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
            'name' => 'E2E NEW CONV COMPANY S.A.',
            'email' => 'e2e-new@test.com',
            'tax_id' => '11.111.111-1',
            'company_code' => 'E2ENEW',
            'fantasy_name' => 'E2E NEW CONV CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '560000000099',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Test Address 123',
            'contact_name' => 'Test Contact',
            'contact_phone_number' => '560000000088',
            'branch_code' => 'EN01',
            'fantasy_name' => 'E2E New Conv Branch',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'E2E NEW CONV USER',
            'nickname' => 'TEST.E2ENEW.USER',
            'email' => 'e2e-new-user@test.com',
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
            'name' => 'E2E New Conv Reminder',
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

    public function test_full_flow_no_prior_conversation(): void
    {
        // =================================================================
        // PHASE 1: reminders:process creates conversation + template + pending
        // =================================================================
        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        $this->assertEquals(1, Conversation::count());
        $conversation = Conversation::first();

        // 1 template outbound
        $this->assertEquals(1, Message::where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'template',
        ]);

        // 3 pending notified menus
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'pending')->count());

        // 1 pending notification waiting
        $this->assertEquals(1, ReminderPendingNotification::where('status', 'waiting_response')->count());
        $pending = ReminderPendingNotification::first();
        $this->assertCount(3, $pending->menu_ids);

        // =================================================================
        // PHASE 2: User responds to template via WhatsApp webhook
        // =================================================================
        $this->postJson('/api/v1/whatsapp/webhook', [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'contacts' => [['wa_id' => $conversation->phone_number, 'profile' => ['name' => 'Test User']]],
                        'messages' => [[
                            'from' => $conversation->phone_number,
                            'id' => 'wamid.webhook_' . uniqid(),
                            'type' => 'text',
                            'text' => ['body' => 'Hola, quiero ver los menus'],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        // 2 messages now (template + inbound), reminder tables unchanged
        $this->assertEquals(2, Message::where('conversation_id', $conversation->id)->count());
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'pending')->count());
        $this->assertEquals(1, ReminderPendingNotification::where('status', 'waiting_response')->count());

        // =================================================================
        // PHASE 3: reminders:check-pending sends the reminder
        // =================================================================
        $this->artisan('reminders:check-pending')
            ->assertSuccessful();

        // 3 messages: template + inbound + reminder
        $this->assertEquals(3, Message::where('conversation_id', $conversation->id)->count());

        $reminderMsg = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('type', 'text')
            ->first();
        $this->assertNotNull($reminderMsg);
        $this->assertEquals('Hay 3 nuevos menus: Menu Lunes, Menu Martes, Menu Miercoles', $reminderMsg->body);

        // Pending notification → sent
        $this->assertEquals(0, ReminderPendingNotification::where('status', 'waiting_response')->count());
        $this->assertEquals(1, ReminderPendingNotification::where('status', 'sent')->count());

        // Notified menus → sent with notified_at
        $this->assertEquals(0, ReminderNotifiedMenu::where('status', 'pending')->count());
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'sent')->count());
        foreach (ReminderNotifiedMenu::all() as $notified) {
            $this->assertNotNull($notified->notified_at);
        }

        // NEGATIVE VALIDATIONS
        $this->assertEquals(1, Conversation::count());
        Http::assertSentCount(2); // 1 template (ConversationObserver) + 1 text reminder (MessageObserver)
        $this->assertEquals(3, ReminderNotifiedMenu::count());
    }
}