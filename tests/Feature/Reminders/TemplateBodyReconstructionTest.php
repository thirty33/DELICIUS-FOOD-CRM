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
use App\Models\Integration;
use App\Models\Menu;
use App\Models\Message;
use App\Models\Permission;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Validates that reminder messages store the reconstructed template body
 * and metadata with template_name, not just "Template: template_name".
 */
class TemplateBodyReconstructionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

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
        config(['whatsapp.initial_template_name' => 'retomar_conversacion']);
        config(['reminders.shop_url' => 'test-shop.example.com']);

        $testPhone = config('whatsapp.test_phone_number');

        Http::fake([
            '*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+'.$testPhone, 'wa_id' => $testPhone]],
                'messages' => [['id' => 'wamid.fake_body_test']],
            ], 200),
        ]);

        $this->createWhatsAppIntegration();
        $this->createTestData();
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

    private function createTestData(): void
    {
        $role = Role::create(['name' => RoleName::AGREEMENT->value]);
        $permission = Permission::create(['name' => PermissionName::INDIVIDUAL->value]);
        $priceList = PriceList::create(['name' => 'Test PL']);

        $this->company = Company::create([
            'name' => 'BODY TEST COMPANY',
            'email' => 'body-test@test.com',
            'tax_id' => '22.222.222-2',
            'company_code' => 'BODYTEST',
            'fantasy_name' => 'BODY TEST CO',
            'price_list_id' => $priceList->id,
            'phone_number' => '0000000000',
        ]);

        $this->branch = Branch::create([
            'company_id' => $this->company->id,
            'address' => 'Body Test Address',
            'contact_name' => 'Body Test Contact',
            'contact_phone_number' => '0000000001',
            'branch_code' => 'BRBODY',
            'fantasy_name' => 'Body Test Branch',
            'min_price_order' => 0,
        ]);

        $user = User::create([
            'name' => 'BODY TEST USER',
            'nickname' => 'BODY.TEST.USER',
            'email' => 'body-test-user@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);
        $user->roles()->attach($role->id);
        $user->permissions()->attach($permission->id);

        $this->campaign = Campaign::create([
            'name' => 'Body Reconstruction Test',
            'type' => CampaignType::REMINDER->value,
            'channel' => 'whatsapp',
            'status' => CampaignStatus::ACTIVE->value,
            'content' => 'Hay {{menu_count}} nuevos menus',
        ]);
        $this->campaign->companies()->attach($this->company->id);

        $this->trigger = CampaignTrigger::create([
            'campaign_id' => $this->campaign->id,
            'trigger_type' => TriggerType::EVENT->value,
            'event_type' => CampaignEventType::MENU_CREATED->value,
            'hours_after' => 0,
            'is_active' => true,
        ]);

        $this->menu = Menu::create([
            'title' => 'Menu Body Test',
            'description' => 'Test menu',
            'active' => true,
            'role_id' => $role->id,
            'permissions_id' => $permission->id,
            'publication_date' => now()->toDateString(),
            'max_order_date' => now()->addDays(1)->toDateTimeString(),
        ]);
    }

    public function test_reminder_creates_message_with_reconstructed_body(): void
    {
        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        $message = Message::where('type', 'template')
            ->where('direction', 'outbound')
            ->latest()
            ->first();

        $this->assertNotNull($message);

        // Body must contain the reconstructed template text, not "Template: name"
        $this->assertStringNotContainsString('Template:', $message->body);
        $this->assertStringContainsString('menús disponibles', $message->body);
        $this->assertStringContainsString('test-shop.example.com', $message->body);

        // Metadata must contain the template name
        $this->assertIsArray($message->metadata);
        $templateName = config('reminders.templates.menu_created.name');
        $this->assertEquals($templateName, $message->metadata['template_name']);
    }

    public function test_reminder_sends_http_notification_with_message_id(): void
    {
        $this->artisan('reminders:process', ['--event' => 'menu_created'])
            ->assertSuccessful();

        // Verify HTTP was called to send the template
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/messages')
                && $request['type'] === 'template';
        });

        // Verify the message exists in the database
        $message = Message::where('type', 'template')
            ->where('direction', 'outbound')
            ->latest()
            ->first();

        $this->assertNotNull($message);
        $this->assertNotNull($message->id);
    }
}
