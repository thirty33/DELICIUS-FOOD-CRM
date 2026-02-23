<?php

namespace Tests\Feature\Chat;

use App\Actions\Conversations\CreateConversationAction;
use App\Enums\ConversationStatus;
use App\Enums\IntegrationName;
use App\Enums\IntegrationType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\PriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversationDuplicateProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $branch;

    private string $testPhone = '560000000099';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);

        Http::fake([
            '*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+'.$this->testPhone, 'wa_id' => $this->testPhone]],
                'messages' => [['id' => 'wamid.test_dup_'.uniqid()]],
            ], 200),
        ]);

        Integration::create([
            'name' => IntegrationName::WHATSAPP,
            'url' => 'https://graph.facebook.com/v24.0',
            'url_test' => 'https://graph.facebook.com/v24.0',
            'type' => IntegrationType::MESSAGING,
            'production' => false,
            'active' => true,
        ]);

        $priceList = PriceList::create(['name' => 'Test Price List']);

        $this->company = Company::factory()->create([
            'phone_number' => $this->testPhone,
            'price_list_id' => $priceList->id,
        ]);

        $this->branch = Branch::factory()->create([
            'company_id' => $this->company->id,
            'contact_phone_number' => $this->testPhone,
        ]);

        config(['whatsapp.test_phone_number' => $this->testPhone]);
        config(['whatsapp.phone_number_id' => 'TEST_PHONE_ID']);
        config(['whatsapp.api_token' => 'test_token']);
        config(['whatsapp.initial_template_name' => 'retomar_conversacion']);
        config(['whatsapp.initial_template_language' => 'en']);
    }

    private function buildWebhookPayload(string $from, string $body, ?string $contactName = 'Test Client'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WHATSAPP_BUSINESS_ACCOUNT_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '56912345678',
                            'phone_number_id' => 'PHONE_NUMBER_ID',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => $contactName],
                            'wa_id' => $from,
                        ]],
                        'messages' => [
                            [
                                'from' => $from,
                                'id' => 'wamid.'.uniqid(),
                                'timestamp' => (string) time(),
                                'type' => 'text',
                                'text' => ['body' => $body],
                            ],
                        ],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    public function test_webhook_does_not_create_duplicate_for_active_conversation(): void
    {
        $conversation = Conversation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'phone_number' => $this->testPhone,
            'client_name' => 'Test Client',
            'status' => ConversationStatus::RECEIVED,
        ]);

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $this->testPhone,
            'Hello again'
        ));

        $this->assertEquals(1, Conversation::where('phone_number', $this->testPhone)->count());

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Hello again',
        ]);
    }

    public function test_webhook_creates_new_when_only_closed_exists(): void
    {
        Conversation::create([
            'company_id' => $this->company->id,
            'phone_number' => $this->testPhone,
            'client_name' => 'Test Client',
            'status' => ConversationStatus::CLOSED,
        ]);

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $this->testPhone,
            'New message after close'
        ));

        $this->assertEquals(2, Conversation::where('phone_number', $this->testPhone)->count());
    }

    public function test_filament_create_action_uses_first_or_create(): void
    {
        $conversation1 = CreateConversationAction::execute([
            'source_type' => 'branch',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $conversation2 = CreateConversationAction::execute([
            'source_type' => 'branch',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->assertEquals($conversation1->id, $conversation2->id);
        $this->assertEquals(1, Conversation::where('phone_number', $this->testPhone)->count());
    }

    public function test_filament_and_webhook_share_same_conversation(): void
    {
        $conversation = CreateConversationAction::execute([
            'source_type' => 'branch',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'without_events' => true,
        ]);

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $this->testPhone,
            'Responding to filament conversation'
        ));

        $this->assertEquals(1, Conversation::where('phone_number', $this->testPhone)->count());

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Responding to filament conversation',
        ]);
    }

    public function test_does_not_create_duplicate_on_repeated_webhooks(): void
    {
        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $this->testPhone,
            'First message'
        ));

        $this->assertEquals(1, Conversation::where('phone_number', $this->testPhone)->count());

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $this->testPhone,
            'Second message'
        ));

        $this->assertEquals(1, Conversation::where('phone_number', $this->testPhone)->count());

        $conversation = Conversation::where('phone_number', $this->testPhone)->first();
        $this->assertEquals(2, $conversation->messages()->where('direction', 'inbound')->count());
    }

    public function test_concurrent_messages_in_same_payload(): void
    {
        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WHATSAPP_BUSINESS_ACCOUNT_ID',
                'changes' => [[
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '56912345678',
                            'phone_number_id' => 'PHONE_NUMBER_ID',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Test Client'],
                            'wa_id' => $this->testPhone,
                        ]],
                        'messages' => [
                            [
                                'from' => $this->testPhone,
                                'id' => 'wamid.msg1_'.uniqid(),
                                'timestamp' => (string) time(),
                                'type' => 'text',
                                'text' => ['body' => 'Message one'],
                            ],
                            [
                                'from' => $this->testPhone,
                                'id' => 'wamid.msg2_'.uniqid(),
                                'timestamp' => (string) time(),
                                'type' => 'text',
                                'text' => ['body' => 'Message two'],
                            ],
                        ],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];

        $this->postJson(route('v1.whatsapp.webhook'), $payload);

        $this->assertEquals(1, Conversation::where('phone_number', $this->testPhone)->count());

        $conversation = Conversation::where('phone_number', $this->testPhone)->first();
        $this->assertEquals(2, $conversation->messages()->where('direction', 'inbound')->count());
    }

    public function test_different_phone_numbers_create_separate_conversations(): void
    {
        $otherPhone = '560000000088';

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $this->testPhone,
            'From phone A'
        ));

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $otherPhone,
            'From phone B'
        ));

        $this->assertEquals(1, Conversation::where('phone_number', $this->testPhone)->count());
        $this->assertEquals(1, Conversation::where('phone_number', $otherPhone)->count());
        $this->assertEquals(2, Conversation::count());
    }
}
