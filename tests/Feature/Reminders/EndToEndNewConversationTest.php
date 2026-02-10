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
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-End Test 1: Full flow without prior conversation.
 *
 * FLOW:
 * 1. reminders:process → creates conversation + sends template + marks sent
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
        // PHASE 1: reminders:process creates conversation + sends template
        // =================================================================
        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        $this->assertEquals(1, Conversation::count());
        $conversation = Conversation::first();

        // Template message recorded in conversation
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'template',
        ]);

        // 3 notified menus, all sent directly
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'sent')->count());
        $this->assertEquals(0, ReminderNotifiedMenu::where('status', 'pending')->count());

        // NEGATIVE VALIDATIONS
        $this->assertEquals(1, Conversation::count());
        $this->assertEquals(3, ReminderNotifiedMenu::count());
    }
}