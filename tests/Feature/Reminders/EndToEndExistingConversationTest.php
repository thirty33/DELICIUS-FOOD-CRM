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
 * End-to-End Test 2: Two batches with existing conversation.
 *
 * FLOW:
 * Phase 1: reminders:process (batch 1) → creates conversation + sends template + marks sent
 * Phase 2: Create new menus → reminders:process reuses conversation + sends template + marks sent
 *
 * VALIDATES:
 * - Existing conversation is reused (no duplicate)
 * - Second batch sends a new template
 * - All menus marked as sent
 * - No duplication of batch 1 notifications
 */
class EndToEndExistingConversationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Branch $branch;
    private Role $role;
    private Permission $permission;
    private Campaign $campaign;
    private CampaignTrigger $trigger;

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
            'name' => 'E2E EXISTING CONV COMPANY S.A.',
            'email' => 'e2e-existing@test.com',
            'tax_id' => '11.111.111-3',
            'company_code' => 'E2EEXT',
            'fantasy_name' => 'E2E EXISTING CONV CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '560000000077',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Test Address 789',
            'contact_name' => 'Test Contact',
            'contact_phone_number' => '560000000066',
            'branch_code' => 'EX01',
            'fantasy_name' => 'E2E Existing Conv Branch',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'E2E EXISTING CONV USER',
            'nickname' => 'TEST.E2EEXT.USER',
            'email' => 'e2e-ext-user@test.com',
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
            'name' => 'E2E Existing Conv Reminder',
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

    public function test_reuses_existing_conversation_for_second_batch(): void
    {
        // =================================================================
        // PHASE 1: First batch → creates conversation + sends template
        // =================================================================
        $batch1 = $this->createMenuBatch(['Lunes', 'Martes', 'Miercoles'], 0);

        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        $this->assertEquals(1, Conversation::count());
        $conversation = Conversation::first();

        // Template message recorded
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'template',
        ]);

        // 3 notified menus, all sent
        $this->assertEquals(3, ReminderNotifiedMenu::where('status', 'sent')->count());

        // =================================================================
        // PHASE 2: Second batch → reuses conversation + sends template
        // =================================================================
        $batch2 = $this->createMenuBatch(['Lunes', 'Martes', 'Miercoles'], 1);

        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        // A. Still 1 conversation (reused existing)
        $this->assertEquals(1, Conversation::count());

        // B. 6 notified menus total (3 batch 1 + 3 batch 2), all sent
        $this->assertEquals(6, ReminderNotifiedMenu::count());
        $this->assertEquals(6, ReminderNotifiedMenu::where('status', 'sent')->count());

        // C. Batch 2 menus all marked as sent
        $batch2MenuIds = collect($batch2)->pluck('id')->toArray();
        $batch2Notifications = ReminderNotifiedMenu::whereIn('menu_id', $batch2MenuIds)->get();
        $this->assertCount(3, $batch2Notifications);
        foreach ($batch2Notifications as $notified) {
            $this->assertEquals('sent', $notified->status);
            $this->assertNotNull($notified->notified_at);
        }

        // D. Batch 1 menus not duplicated
        $batch1MenuIds = collect($batch1)->pluck('id')->toArray();
        foreach ($batch1MenuIds as $menuId) {
            $this->assertEquals(1, ReminderNotifiedMenu::where('menu_id', $menuId)->count());
        }
    }
}
