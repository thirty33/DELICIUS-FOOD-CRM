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
use App\Models\CampaignExecution;
use App\Models\CampaignTrigger;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\ReminderNotifiedMenu;
use App\Models\Role;
use App\Models\User;
use App\Services\Reminders\ProcessRemindersService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Test 3: Two batches of menus without notification duplication.
 *
 * SCENARIO:
 * Batch 1: 3 menus created (week 1) → processed → 1 conversation, 1 template,
 *          3 reminder_notified_menus (all sent).
 * Batch 2: 3 new menus created (week 2) → processed → reuses conversation,
 *          sends new template, 3 NEW reminder_notified_menus (total 6, all sent).
 *
 * VALIDATES:
 * - Second batch does NOT duplicate notifications for first batch menus
 * - Second batch only processes new menus
 * - All notifications are marked as sent
 */
class ProcessMenuCreatedTwoBatchesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Branch $branch;
    private Role $role;
    private Permission $permission;
    private Campaign $campaign;
    private CampaignTrigger $trigger;
    private array $batch1Menus = [];
    private array $batch2Menus = [];

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
            'name' => 'BATCH TEST COMPANY S.A.',
            'email' => 'batch-test@test.com',
            'tax_id' => '11.111.111-1',
            'company_code' => 'BATCH',
            'fantasy_name' => 'BATCH TEST CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '560000000099',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Test Address 123',
            'contact_name' => 'Test Contact',
            'contact_phone_number' => '560000000088',
            'branch_code' => 'BT01',
            'fantasy_name' => 'Batch Test Branch',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'BATCH TEST USER',
            'nickname' => 'TEST.BATCH.USER',
            'email' => 'batch-user@test.com',
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
            'name' => 'Test Two Batches Reminder',
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

    private function createMenuBatch(array $days, int $weekOffset): array
    {
        $menus = [];

        foreach ($days as $i => $day) {
            $menus[] = Menu::create([
                'title' => "Menu {$day} S" . ($weekOffset + 1),
                'description' => "Menu de {$day} semana " . ($weekOffset + 1),
                'active' => true,
                'role_id' => $this->role->id,
                'permissions_id' => $this->permission->id,
                'publication_date' => now()->addWeeks($weekOffset)->addDays($i)->toDateString(),
                'max_order_date' => now()->addWeeks($weekOffset)->addDays($i + 1)->toDateTimeString(),
            ]);
        }

        return $menus;
    }

    // =========================================================================
    // TEST
    // =========================================================================

    public function test_second_batch_does_not_duplicate_notifications_from_first_batch(): void
    {
        /** @var ProcessRemindersService $service */
        $service = app(ProcessRemindersService::class);

        // =================================================================
        // PHASE 1: First batch of menus (week 1)
        // =================================================================
        $this->batch1Menus = $this->createMenuBatch(['Lunes', 'Martes', 'Miercoles'], 0);

        $result1 = $service->processEventType(CampaignEventType::MENU_CREATED);

        // A. Service returns correct totals for phase 1
        $this->assertEquals(1, $result1['triggers_processed']);
        $this->assertEquals(1, $result1['sent']);
        $this->assertEquals(0, $result1['pending']);
        $this->assertEquals(0, $result1['failed']);
        $this->assertEquals(0, $result1['skipped']);

        // B. 1 conversation created
        $this->assertEquals(1, Conversation::count());

        // C. 3 reminder_notified_menus (3 menus × 1 recipient), all sent
        $this->assertEquals(3, ReminderNotifiedMenu::count());
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'sent')->count());

        // =================================================================
        // PHASE 2: Second batch of menus (week 2)
        // =================================================================
        $this->batch2Menus = $this->createMenuBatch(['Lunes', 'Martes', 'Miercoles'], 1);

        $result2 = $service->processEventType(CampaignEventType::MENU_CREATED);

        // D. Service returns correct totals for phase 2
        $this->assertEquals(1, $result2['triggers_processed']);
        $this->assertEquals(1, $result2['sent']);
        $this->assertEquals(0, $result2['pending']);
        $this->assertEquals(0, $result2['failed']);
        $this->assertEquals(0, $result2['skipped']);

        // E. Still 1 conversation (reused existing)
        $this->assertEquals(1, Conversation::count());

        // F. 6 reminder_notified_menus total (3 batch 1 + 3 batch 2), all sent
        $this->assertEquals(6, ReminderNotifiedMenu::count());
        $this->assertEquals(6, ReminderNotifiedMenu::where('status', 'sent')->count());

        // Each batch has exactly 3 records
        foreach ($this->batch1Menus as $menu) {
            $this->assertEquals(1, ReminderNotifiedMenu::where('menu_id', $menu->id)->count());
        }
        foreach ($this->batch2Menus as $menu) {
            $this->assertEquals(1, ReminderNotifiedMenu::where('menu_id', $menu->id)->count());
        }

        // G. 2 campaign executions (one per processing run)
        $this->assertEquals(2, CampaignExecution::count());
        $this->assertEquals(
            2,
            CampaignExecution::where('status', CampaignExecutionStatus::COMPLETED->value)->count()
        );

        // =================================================================
        // NEGATIVE VALIDATIONS
        // =================================================================

        // No pending or failed notifications
        $this->assertDatabaseMissing('reminder_notified_menus', [
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('reminder_notified_menus', [
            'status' => 'failed',
        ]);

        // No duplicate records in reminder_notified_menus
        $uniqueCombinations = ReminderNotifiedMenu::select('trigger_id', 'menu_id', 'phone_number')
            ->distinct()
            ->count();
        $this->assertEquals(6, $uniqueCombinations);
    }
}