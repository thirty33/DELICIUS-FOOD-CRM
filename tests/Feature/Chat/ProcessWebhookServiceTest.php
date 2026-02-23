<?php

namespace Tests\Feature\Chat;

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

class ProcessWebhookServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);

        Http::fake([
            '*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+560000000001', 'wa_id' => '560000000001']],
                'messages' => [['id' => 'wamid.test_'.uniqid()]],
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

        config(['whatsapp.test_phone_number' => '560000000001']);
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

    private function buildStatusOnlyPayload(): array
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
                        'statuses' => [[
                            'id' => 'wamid.status123',
                            'status' => 'delivered',
                            'timestamp' => (string) time(),
                            'recipient_id' => '560000000001',
                        ]],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    public function test_processes_message_for_existing_conversation(): void
    {
        $phone = '560000000001';

        $conversation = Conversation::create([
            'phone_number' => $phone,
            'client_name' => 'Existing Client',
            'status' => ConversationStatus::NEW_CONVERSATION,
        ]);

        $response = $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $phone,
            'Hello from existing'
        ));

        $response->assertStatus(200)->assertJson(['status' => 'received']);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Hello from existing',
        ]);

        $conversation->refresh();
        $this->assertEquals(ConversationStatus::RECEIVED, $conversation->status);
    }

    public function test_creates_conversation_when_branch_phone_matches(): void
    {
        $phone = '560000000022';
        $priceList = PriceList::create(['name' => 'Test PL']);

        $company = Company::factory()->create([
            'price_list_id' => $priceList->id,
        ]);

        Branch::factory()->create([
            'company_id' => $company->id,
            'contact_phone_number' => '56 0000 000 022',
        ]);

        config(['whatsapp.test_phone_number' => $phone]);

        $response = $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $phone,
            'Message from branch phone'
        ));

        $response->assertStatus(200);

        $conversation = Conversation::where('phone_number', $phone)->first();
        $this->assertNotNull($conversation);
        $this->assertEquals($company->id, $conversation->company_id);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Message from branch phone',
        ]);
    }

    public function test_creates_conversation_when_company_phone_matches(): void
    {
        $phone = '560000000033';
        $priceList = PriceList::create(['name' => 'Test PL']);

        Company::factory()->create([
            'phone_number' => '56 0000 000 033',
            'price_list_id' => $priceList->id,
        ]);

        config(['whatsapp.test_phone_number' => $phone]);

        $response = $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $phone,
            'Message from company phone'
        ));

        $response->assertStatus(200);

        $conversation = Conversation::where('phone_number', $phone)->first();
        $this->assertNotNull($conversation);
        $this->assertNull($conversation->branch_id);
    }

    public function test_creates_orphan_conversation_when_no_match(): void
    {
        $phone = '5491112345678';

        $response = $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $phone,
            'Message from unknown',
            'Unknown Person'
        ));

        $response->assertStatus(200);

        $conversation = Conversation::where('phone_number', $phone)->first();
        $this->assertNotNull($conversation);
        $this->assertNull($conversation->company_id);
        $this->assertNull($conversation->branch_id);
        $this->assertEquals('Unknown Person', $conversation->client_name);
    }

    public function test_does_not_create_duplicate_conversation_for_same_phone(): void
    {
        $phone = '560000000044';
        $priceList = PriceList::create(['name' => 'Test PL']);

        $company = Company::factory()->create([
            'phone_number' => $phone,
            'price_list_id' => $priceList->id,
        ]);

        config(['whatsapp.test_phone_number' => $phone]);

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $phone,
            'First message'
        ));

        $this->assertEquals(1, Conversation::where('phone_number', $phone)->count());

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $phone,
            'Second message'
        ));

        $this->assertEquals(1, Conversation::where('phone_number', $phone)->count());

        $conversation = Conversation::where('phone_number', $phone)->first();
        $this->assertEquals(2, $conversation->messages()->where('direction', 'inbound')->count());
    }

    public function test_creates_new_conversation_when_previous_is_closed(): void
    {
        $phone = '560000000055';

        Conversation::create([
            'phone_number' => $phone,
            'client_name' => 'Closed Client',
            'status' => ConversationStatus::CLOSED,
        ]);

        $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            $phone,
            'New message after close'
        ));

        $this->assertEquals(2, Conversation::where('phone_number', $phone)->count());

        $newConversation = Conversation::where('phone_number', $phone)
            ->where('status', '!=', ConversationStatus::CLOSED->value)
            ->first();

        $this->assertNotNull($newConversation);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $newConversation->id,
            'direction' => 'inbound',
        ]);
    }

    public function test_ignores_status_webhooks_without_messages(): void
    {
        $conversationsBefore = Conversation::count();
        $messagesBefore = \App\Models\Message::count();

        $response = $this->postJson(route('v1.whatsapp.webhook'), $this->buildStatusOnlyPayload());

        $response->assertStatus(200);

        $this->assertEquals($conversationsBefore, Conversation::count());
        $this->assertEquals($messagesBefore, \App\Models\Message::count());
    }
}
