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
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\ReminderNotifiedMenu;
use App\Models\ReminderPendingNotification;
use App\Models\Role;
use App\Models\User;
use App\Services\Reminders\ProcessRemindersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test 2: Multiple menus (full week) and multiple companies with
 * different branch/phone configurations in production mode.
 *
 * SCENARIO:
 * 7 menus created (one per day). Campaign associated to 5 companies:
 * - Company A: 3 branches, 2 included in campaign, all with phone
 * - Company B: 2 branches, all included, all with phone
 * - Company C: 3 branches, all included, some without phone (fallback to company)
 * - Company D: 9 branches, 5 included, none with phone (fallback to company)
 * - Company E: 2 branches, all included, no phone anywhere (0 recipients)
 *
 * integration.production = true → each entity uses its own phone number.
 *
 * EXPECTED: 7 unique recipients, 7 conversations, 7 templates sent,
 * 49 reminder_notified_menus (7 menus × 7 recipients), 7 pending notifications.
 */
class ProcessMenuCreatedMultipleCompaniesTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;
    private Permission $permission;
    private Campaign $campaign;
    private CampaignTrigger $trigger;
    private array $menus = [];
    private array $expectedPhones = [];

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
        $this->createCampaignAndTrigger();
        $this->createCompanies();
        $this->createWeekMenus();
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

    private function createCampaignAndTrigger(): void
    {
        $this->campaign = Campaign::create([
            'name' => 'Test Weekly Reminder',
            'type' => CampaignType::REMINDER->value,
            'channel' => 'whatsapp',
            'status' => CampaignStatus::ACTIVE->value,
            'content' => 'Hay {{menu_count}} nuevos menus: {{menus}}',
        ]);

        $this->trigger = CampaignTrigger::create([
            'campaign_id' => $this->campaign->id,
            'trigger_type' => TriggerType::EVENT->value,
            'event_type' => CampaignEventType::MENU_CREATED->value,
            'hours_after' => 0,
            'is_active' => true,
        ]);
    }

    private function createCompanyWithUser(string $name, string $code, string $email, ?string $phone): Company
    {
        $priceList = PriceList::first() ?? PriceList::create(['name' => 'Test Price List']);

        $company = Company::create([
            'name' => $name,
            'email' => $email,
            'tax_id' => '11.111.111-' . rand(0, 9),
            'company_code' => $code,
            'fantasy_name' => $name,
            'price_list_id' => $priceList->id,
            'phone_number' => $phone,
        ]);

        $user = User::create([
            'name' => "User {$code}",
            'nickname' => "TEST.{$code}",
            'email' => "user-{$code}@test.com",
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'active' => true,
        ]);
        $user->roles()->attach($this->role->id);
        $user->permissions()->attach($this->permission->id);

        $this->campaign->companies()->attach($company->id);

        return $company;
    }

    private function createBranch(Company $company, string $code, ?string $phone): Branch
    {
        return Branch::create([
            'company_id' => $company->id,
            'address' => "Address {$code}",
            'contact_name' => "Contact {$code}",
            'contact_phone_number' => $phone,
            'branch_code' => $code,
            'fantasy_name' => "Branch {$code}",
            'min_price_order' => 0,
        ]);
    }

    private function createCompanies(): void
    {
        // =====================================================================
        // Company A: 3 branches, 2 included, all with phone
        // Expected recipients: 560000000011 (A1), 560000000012 (A2)
        // =====================================================================
        $companyA = $this->createCompanyWithUser('COMPANY A S.A.', 'COMPA', 'compA@test.com', '560000000001');
        $branchA1 = $this->createBranch($companyA, 'A1', '560000000011');
        $branchA2 = $this->createBranch($companyA, 'A2', '560000000012');
        $branchA3 = $this->createBranch($companyA, 'A3', '560000000013'); // NOT included

        $this->campaign->branches()->attach([$branchA1->id, $branchA2->id]);

        // =====================================================================
        // Company B: 2 branches, all included, all with phone
        // Expected recipients: 560000000021 (B1), 560000000022 (B2)
        // =====================================================================
        $companyB = $this->createCompanyWithUser('COMPANY B S.A.', 'COMPB', 'compB@test.com', '560000000002');
        $branchB1 = $this->createBranch($companyB, 'B1', '560000000021');
        $branchB2 = $this->createBranch($companyB, 'B2', '560000000022');

        $this->campaign->branches()->attach([$branchB1->id, $branchB2->id]);

        // =====================================================================
        // Company C: 3 branches, all included, some without phone
        // Expected recipients: 560000000031 (C1), 560000000003 (Company C fallback)
        // =====================================================================
        $companyC = $this->createCompanyWithUser('COMPANY C S.A.', 'COMPC', 'compC@test.com', '560000000003');
        $branchC1 = $this->createBranch($companyC, 'C1', '560000000031');
        $branchC2 = $this->createBranch($companyC, 'C2', null); // no phone → fallback company
        $branchC3 = $this->createBranch($companyC, 'C3', null); // no phone → fallback company

        $this->campaign->branches()->attach([$branchC1->id, $branchC2->id, $branchC3->id]);

        // =====================================================================
        // Company D: 9 branches, 5 included, none with phone
        // Expected recipients: 560000000004 (Company D fallback, deduplicated)
        // =====================================================================
        $companyD = $this->createCompanyWithUser('COMPANY D S.A.', 'COMPD', 'compD@test.com', '560000000004');
        $includedD = [];
        for ($i = 1; $i <= 9; $i++) {
            $branch = $this->createBranch($companyD, "D{$i}", null);
            if ($i <= 5) {
                $includedD[] = $branch->id;
            }
        }
        $this->campaign->branches()->attach($includedD);

        // =====================================================================
        // Company E: 2 branches, all included, NO phone anywhere
        // Expected recipients: 0 (no phone on branches or company)
        // =====================================================================
        $companyE = $this->createCompanyWithUser('COMPANY E S.A.', 'COMPE', 'compE@test.com', null);
        $branchE1 = $this->createBranch($companyE, 'E1', null);
        $branchE2 = $this->createBranch($companyE, 'E2', null);

        $this->campaign->branches()->attach([$branchE1->id, $branchE2->id]);

        $this->expectedPhones = [
            '560000000011', '560000000012',
            '560000000021', '560000000022',
            '560000000031', '560000000003',
            '560000000004',
        ];
    }

    private function createWeekMenus(): void
    {
        $days = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];

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

    public function test_multiple_menus_and_companies_creates_pending_for_each_recipient(): void
    {
        /** @var ProcessRemindersService $service */
        $service = app(ProcessRemindersService::class);

        $result = $service->processEventType(CampaignEventType::MENU_CREATED);

        // A. Service returns correct totals
        $this->assertEquals(1, $result['triggers_processed']);
        $this->assertEquals(0, $result['sent']);
        $this->assertEquals(7, $result['pending']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);

        // B. Exactly 7 conversations created (one per unique phone)
        $this->assertEquals(7, Conversation::count());
        foreach ($this->expectedPhones as $phone) {
            $this->assertDatabaseHas('conversations', [
                'phone_number' => $phone,
            ]);
        }

        // C. WhatsApp template sent for each conversation (7 templates)
        $templateMessages = \App\Models\Message::where('direction', 'outbound')
            ->where('type', 'template')
            ->count();
        $this->assertEquals(7, $templateMessages);
        Http::assertSentCount(7);

        // D. 49 records in reminder_notified_menus (7 menus × 7 recipients)
        $this->assertEquals(49, ReminderNotifiedMenu::count());
        $this->assertEquals(0, ReminderNotifiedMenu::where('status', 'sent')->count());
        $this->assertEquals(49, ReminderNotifiedMenu::where('status', 'pending')->count());

        // Each menu has 7 notification records
        foreach ($this->menus as $menu) {
            $this->assertEquals(
                7,
                ReminderNotifiedMenu::where('menu_id', $menu->id)->count()
            );
        }

        // Each phone has 7 notification records (one per menu)
        foreach ($this->expectedPhones as $phone) {
            $this->assertEquals(
                7,
                ReminderNotifiedMenu::where('phone_number', $phone)->count()
            );
        }

        // E. 7 pending notification records (one per recipient)
        $this->assertEquals(7, ReminderPendingNotification::count());
        $this->assertEquals(
            7,
            ReminderPendingNotification::where('status', 'waiting_response')->count()
        );

        // Each pending notification contains all 7 menu IDs
        $menuIds = collect($this->menus)->pluck('id')->sort()->values()->toArray();
        $pendingNotifications = ReminderPendingNotification::all();
        foreach ($pendingNotifications as $pending) {
            $storedMenuIds = collect($pending->menu_ids)->sort()->values()->toArray();
            $this->assertEquals($menuIds, $storedMenuIds);
        }

        // F. Campaign execution recorded
        $this->assertDatabaseHas('campaign_executions', [
            'campaign_id' => $this->campaign->id,
            'trigger_id' => $this->trigger->id,
            'total_recipients' => 7,
            'sent_count' => 0,
            'failed_count' => 0,
            'status' => CampaignExecutionStatus::COMPLETED->value,
        ]);

        // G. Trigger updated
        $this->trigger->refresh();
        $this->assertNotNull($this->trigger->last_executed_at);

        // =====================================================================
        // NEGATIVE VALIDATIONS
        // =====================================================================

        // Branch A3 (not included) should NOT have any notification
        $this->assertDatabaseMissing('conversations', [
            'phone_number' => '560000000013',
        ]);

        // Company E (no phone) should NOT have any conversation
        $companyE = Company::where('company_code', 'COMPE')->first();
        $this->assertEquals(0, Conversation::where('company_id', $companyE->id)->count());

        // No sent notifications
        $this->assertDatabaseMissing('reminder_notified_menus', [
            'status' => 'sent',
        ]);
    }
}