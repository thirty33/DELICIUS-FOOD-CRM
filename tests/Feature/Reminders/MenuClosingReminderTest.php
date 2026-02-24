<?php

namespace Tests\Feature\Reminders;

use App\Enums\CampaignEventType;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\IntegrationName;
use App\Enums\IntegrationType;
use App\Enums\OrderStatus;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TriggerType;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Menu;
use App\Models\Message;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\ReminderNotifiedMenu;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MenuClosingReminderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private User $user;

    private Role $role;

    private Permission $permission;

    private Campaign $campaign;

    private CampaignTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin to a Wednesday so publication_date (tomorrow=Thursday) is always a weekday
        $this->travelTo(Carbon::parse('2026-03-04 16:00', config('app.timezone')));

        config(['queue.default' => 'sync']);
        config(['reminders.test_mode' => false]);
        config(['reminders.shop_url' => 'test-shop.example.com']);

        Http::fake([
            '*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+56900000001', 'wa_id' => '56900000001']],
                'messages' => [['id' => 'wamid.closing_'.uniqid()]],
            ], 200),
        ]);

        $this->createWhatsAppIntegration();
        $this->createBaseData();
        $this->createCampaignWithTrigger();
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

    private function createBaseData(): void
    {
        $this->role = Role::create(['name' => RoleName::AGREEMENT->value]);
        $this->permission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);

        $priceList = PriceList::create(['name' => 'Test Price List']);

        $this->company = Company::create([
            'name' => 'CLOSING TEST COMPANY',
            'email' => 'closing-test@test.com',
            'tax_id' => '33.333.333-3',
            'company_code' => 'CLOSTEST',
            'fantasy_name' => 'CLOSING TEST CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '56900000000',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Test Address',
            'contact_name' => 'Test Contact',
            'contact_phone_number' => '56900000001',
            'branch_code' => 'BRCLOSE',
            'fantasy_name' => 'Test Branch Closing',
            'min_price_order' => 0,
        ]);

        $this->user = User::create([
            'name' => 'CLOSING TEST USER',
            'nickname' => 'CLOSING.TEST.USER',
            'email' => 'closing-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);
        $this->user->roles()->attach($this->role->id);
        $this->user->permissions()->attach($this->permission->id);
    }

    private function createCampaignWithTrigger(): void
    {
        $this->campaign = Campaign::create([
            'name' => 'Test Menu Closing Reminder',
            'type' => CampaignType::REMINDER->value,
            'channel' => 'whatsapp',
            'status' => CampaignStatus::ACTIVE->value,
            'content' => 'Recordatorio de pedido pendiente',
        ]);

        $this->campaign->companies()->attach($this->company->id);

        $this->trigger = CampaignTrigger::create([
            'campaign_id' => $this->campaign->id,
            'trigger_type' => TriggerType::EVENT->value,
            'event_type' => CampaignEventType::MENU_CLOSING->value,
            'hours_before' => 3,
            'is_active' => true,
        ]);
    }

    private function createMenuClosingSoon(string $title = 'Menu Closing Soon', int $hoursUntilClose = 2): Menu
    {
        return Menu::create([
            'title' => $title,
            'description' => 'Test menu',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => now()->addDay()->toDateString(),
            'max_order_date' => now()->addHours($hoursUntilClose)->toDateTimeString(),
        ]);
    }

    private function createOrder(Menu $menu, string $status = 'PROCESSED', ?int $branchId = null): Order
    {
        return Order::create([
            'user_id' => $this->user->id,
            'branch_id' => $branchId ?? $this->branch->id,
            'dispatch_date' => $menu->publication_date,
            'status' => $status,
            'order_number' => 'ORD-'.uniqid(),
        ]);
    }

    // =========================================================================
    // FLOW TESTS
    // =========================================================================

    public function test_sends_notification_to_branch_without_order(): void
    {
        $menu = $this->createMenuClosingSoon();

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $branchPhone = $this->branch->contact_phone_number;

        $this->assertDatabaseHas('conversations', [
            'phone_number' => $branchPhone,
            'company_id' => $this->company->id,
        ]);

        $message = Message::where('type', 'template')
            ->where('direction', 'outbound')
            ->latest()
            ->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('pedido para mañana', $message->body);
        $this->assertStringContainsString('test-shop.example.com', $message->body);

        $templateName = config('reminders.templates.menu_closing.name');
        $this->assertEquals($templateName, $message->metadata['template_name']);

        $this->assertDatabaseHas('reminder_notified_menus', [
            'trigger_id' => $this->trigger->id,
            'menu_id' => $menu->id,
            'phone_number' => $branchPhone,
            'status' => 'sent',
        ]);
    }

    public function test_skips_branch_with_processed_order(): void
    {
        $menu = $this->createMenuClosingSoon();
        $this->createOrder($menu, OrderStatus::PROCESSED->value);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(0, Message::where('type', 'template')->count());
        $this->assertEquals(0, ReminderNotifiedMenu::count());
    }

    public function test_skips_branch_with_partially_scheduled_order(): void
    {
        $menu = $this->createMenuClosingSoon();
        $this->createOrder($menu, OrderStatus::PARTIALLY_SCHEDULED->value);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(0, Message::where('type', 'template')->count());
    }

    public function test_notifies_branch_with_canceled_order(): void
    {
        $menu = $this->createMenuClosingSoon();
        $this->createOrder($menu, OrderStatus::CANCELED->value);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(1, Message::where('type', 'template')->count());
        $this->assertDatabaseHas('reminder_notified_menus', [
            'menu_id' => $menu->id,
            'status' => 'sent',
        ]);
    }

    public function test_does_not_notify_for_already_closed_menus(): void
    {
        Menu::create([
            'title' => 'Already Closed Menu',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => now()->subDay()->toDateString(),
            'max_order_date' => now()->subHour()->toDateTimeString(),
        ]);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(0, Message::where('type', 'template')->count());
    }

    public function test_does_not_notify_for_menus_closing_outside_window(): void
    {
        Menu::create([
            'title' => 'Menu Far Away',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => now()->addDays(2)->toDateString(),
            'max_order_date' => now()->addHours(5)->toDateTimeString(),
        ]);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(0, Message::where('type', 'template')->count());
    }

    public function test_excludes_inactive_menus(): void
    {
        Menu::create([
            'title' => 'Inactive Menu',
            'description' => 'Test',
            'active' => false,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => now()->addDay()->toDateString(),
            'max_order_date' => now()->addHours(2)->toDateTimeString(),
        ]);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(0, Message::where('type', 'template')->count());
    }

    public function test_skips_already_notified_recipient(): void
    {
        $this->createMenuClosingSoon();

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(1, Message::where('type', 'template')->count());

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        // Still only 1 template message (second run was skipped)
        $this->assertEquals(1, Message::where('type', 'template')->count());
    }

    public function test_test_mode_uses_short_lookback_window(): void
    {
        config(['reminders.test_mode' => true]);
        config(['reminders.test_mode_lookback_minutes' => 10]);

        // Menu closing in 8 minutes (within 10 min lookback)
        Menu::create([
            'title' => 'Test Mode Menu',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => now()->addDay()->toDateString(),
            'max_order_date' => now()->addMinutes(8)->toDateTimeString(),
        ]);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(1, Message::where('type', 'template')->count());
    }

    public function test_menu_created_and_menu_closing_dont_interfere(): void
    {
        // Menu closing soon (for menu_closing)
        $this->createMenuClosingSoon('Menu Closing', 2);

        // Recently created menu (for menu_created) - far away closing date
        Menu::create([
            'title' => 'Recently Created Menu',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => now()->addDays(3)->toDateString(),
            'max_order_date' => now()->addDays(4)->toDateTimeString(),
        ]);

        // Process menu_closing only
        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        // Only 1 notification (for the closing menu, not the created one)
        $this->assertEquals(1, ReminderNotifiedMenu::where('trigger_id', $this->trigger->id)->count());
    }

    public function test_multiple_branches_branch_with_order_skipped_branch_without_notified(): void
    {
        $branch2 = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Second Address',
            'contact_name' => 'Second Contact',
            'contact_phone_number' => '56900000002',
            'branch_code' => 'BRCLOSE2',
            'fantasy_name' => 'Second Branch',
            'min_price_order' => 0,
        ]);

        $menu = $this->createMenuClosingSoon();

        // Branch 1 has a processed order (should be skipped)
        $this->createOrder($menu, OrderStatus::PROCESSED->value, $this->branch->id);

        // Branch 2 has no order (should be notified)

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $branch2Phone = $branch2->contact_phone_number;

        // Only branch 2 should receive a notification
        $notifiedMenus = ReminderNotifiedMenu::where('trigger_id', $this->trigger->id)->get();
        $this->assertEquals(1, $notifiedMenus->count());
        $this->assertEquals($branch2Phone, $notifiedMenus->first()->phone_number);

        // Conversation created for branch 2's phone
        $this->assertDatabaseHas('conversations', [
            'phone_number' => $branch2Phone,
            'branch_id' => $branch2->id,
        ]);
    }

    // =========================================================================
    // CLOSEST MENU & WEEKEND LOGIC TESTS
    // =========================================================================

    public function test_only_notifies_closest_menu_not_subsequent_ones(): void
    {
        // Tuesday 16:00 — trigger hours_before=3
        // Menu Wed closes Tue 18:00 → 2h left → within 3h window → NOTIFY
        // Menu Thu closes Wed 18:00 → 26h left → outside window → NOT notified
        // Menu Fri closes Thu 18:00 → 50h left → outside window → NOT notified
        $tuesday = Carbon::parse('next tuesday 16:00', config('app.timezone'));
        $this->travelTo($tuesday);

        $menuWednesday = Menu::create([
            'title' => 'Menu Miercoles',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => $tuesday->copy()->addDay()->toDateString(),
            'max_order_date' => $tuesday->copy()->startOfDay()->addHours(18)->toDateTimeString(),
        ]);

        $menuThursday = Menu::create([
            'title' => 'Menu Jueves',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => $tuesday->copy()->addDays(2)->toDateString(),
            'max_order_date' => $tuesday->copy()->addDay()->startOfDay()->addHours(18)->toDateTimeString(),
        ]);

        $menuFriday = Menu::create([
            'title' => 'Menu Viernes',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => $tuesday->copy()->addDays(3)->toDateString(),
            'max_order_date' => $tuesday->copy()->addDays(2)->startOfDay()->addHours(18)->toDateTimeString(),
        ]);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        // Only Wednesday notified (the only one closing within 3h)
        $notified = ReminderNotifiedMenu::where('trigger_id', $this->trigger->id)->get();
        $this->assertEquals(1, $notified->count());
        $this->assertEquals($menuWednesday->id, $notified->first()->menu_id);

        $this->assertDatabaseMissing('reminder_notified_menus', ['menu_id' => $menuThursday->id]);
        $this->assertDatabaseMissing('reminder_notified_menus', ['menu_id' => $menuFriday->id]);

        // Second run at same time: Wednesday already notified, should not re-notify
        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        $this->assertEquals(1, ReminderNotifiedMenu::count());
        $this->assertEquals(1, Message::where('type', 'template')->count());
    }

    public function test_friday_skips_saturday_menu_notifies_monday(): void
    {
        // Friday 15:00 — trigger hours_before=3
        // Menu Sat closes Fri 18:00 → 3h left → within window BUT publication_date is Saturday → SKIP
        // Menu Mon closes Sun 18:00 → 51h left → outside window
        // The closest non-weekend menu (Monday) is not yet closing → no notification expected
        // So: nothing should be sent because the only menu in the window is Saturday (weekend)
        $friday = Carbon::parse('next friday 15:00', config('app.timezone'));
        $this->travelTo($friday);

        $menuSaturday = Menu::create([
            'title' => 'Menu Sabado',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => $friday->copy()->addDay()->toDateString(),
            'max_order_date' => $friday->copy()->startOfDay()->addHours(18)->toDateTimeString(),
        ]);

        $menuMonday = Menu::create([
            'title' => 'Menu Lunes',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => $friday->copy()->addDays(3)->toDateString(),
            'max_order_date' => $friday->copy()->addDays(2)->startOfDay()->addHours(18)->toDateTimeString(),
        ]);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        // Saturday is within the window but is a weekend day → should be excluded
        // Monday is not within the window yet → no notification
        $this->assertDatabaseMissing('reminder_notified_menus', ['menu_id' => $menuSaturday->id]);
        $this->assertDatabaseMissing('reminder_notified_menus', ['menu_id' => $menuMonday->id]);
        $this->assertEquals(0, Message::where('type', 'template')->count());
    }

    public function test_normal_weekday_notifies_only_the_one_closing_within_window(): void
    {
        // Wednesday 16:00 — trigger hours_before=3
        // Menu Thu closes Wed 18:00 → 2h left → within 3h window → NOTIFY
        // Menu Fri closes Thu 18:00 → 26h left → outside window → NOT notified
        $wednesday = Carbon::parse('next wednesday 16:00', config('app.timezone'));
        $this->travelTo($wednesday);

        $menuThursday = Menu::create([
            'title' => 'Menu Jueves',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => $wednesday->copy()->addDay()->toDateString(),
            'max_order_date' => $wednesday->copy()->startOfDay()->addHours(18)->toDateTimeString(),
        ]);

        $menuFriday = Menu::create([
            'title' => 'Menu Viernes',
            'description' => 'Test',
            'active' => true,
            'role_id' => $this->role->id,
            'permissions_id' => $this->permission->id,
            'publication_date' => $wednesday->copy()->addDays(2)->toDateString(),
            'max_order_date' => $wednesday->copy()->addDay()->startOfDay()->addHours(18)->toDateTimeString(),
        ]);

        $this->artisan('reminders:process', ['--event' => 'menu_closing'])
            ->assertSuccessful();

        // Only Thursday notified (the only one within the 3h window)
        $notified = ReminderNotifiedMenu::where('trigger_id', $this->trigger->id)->get();
        $this->assertEquals(1, $notified->count());
        $this->assertEquals($menuThursday->id, $notified->first()->menu_id);

        $this->assertDatabaseMissing('reminder_notified_menus', ['menu_id' => $menuFriday->id]);
    }
}
