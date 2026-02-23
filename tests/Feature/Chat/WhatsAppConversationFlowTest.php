<?php

namespace Tests\Feature\Chat;

use App\Actions\Conversations\CreateConversationMessageAction;
use App\Enums\ConversationStatus;
use App\Enums\IntegrationName;
use App\Enums\IntegrationType;
use App\Enums\RoleName;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration test for complete WhatsApp conversation flow.
 *
 * Tests the full lifecycle:
 * 1. Conversation created → Template sent to WhatsApp
 * 2. Client responds via webhook → Inbound message created, admins notified
 * 3. Operator responds → Outbound message sent to WhatsApp
 * 4. Client responds again via webhook → Second inbound message
 */
class WhatsAppConversationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $adminUser;

    private Integration $whatsappIntegration;

    private string $clientPhoneNumber = '573227632472';

    protected function setUp(): void
    {
        parent::setUp();

        // Process queues synchronously for integration testing
        config(['queue.default' => 'sync']);

        // Fake HTTP requests to WhatsApp API
        Http::fake([
            '*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+'.$this->clientPhoneNumber, 'wa_id' => $this->clientPhoneNumber]],
                'messages' => [['id' => 'wamid.test123']],
            ], 200),
        ]);

        $this->createWhatsAppIntegration();
        $this->createCompanyAndAdmin();
    }

    private function createWhatsAppIntegration(): void
    {
        $this->whatsappIntegration = Integration::create([
            'name' => IntegrationName::WHATSAPP,
            'url' => 'https://graph.facebook.com/v24.0',
            'url_test' => 'https://graph.facebook.com/v24.0',
            'type' => IntegrationType::MESSAGING,
            'production' => false,
            'active' => true,
        ]);
    }

    private function createCompanyAndAdmin(): void
    {
        $adminRole = Role::create(['name' => RoleName::ADMIN->value]);
        $priceList = PriceList::create(['name' => 'Test Price List']);

        $this->company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'email' => 'company@test.com',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCO',
            'fantasy_name' => 'TEST CO',
            'price_list_id' => $priceList->id,
            'phone_number' => $this->clientPhoneNumber,
        ]);

        $this->adminUser = User::create([
            'name' => 'TEST ADMIN',
            'nickname' => 'TEST.ADMIN',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);
        $this->adminUser->roles()->attach($adminRole->id);
    }

    private function buildWebhookPayload(string $messageBody, string $messageType = 'text'): array
    {
        $messageContent = match ($messageType) {
            'text' => ['text' => ['body' => $messageBody]],
            'image' => ['image' => ['id' => 'img123', 'caption' => $messageBody]],
            default => ['text' => ['body' => $messageBody]],
        };

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
                            'profile' => ['name' => 'Test Client'],
                            'wa_id' => $this->clientPhoneNumber,
                        ]],
                        'messages' => [
                            array_merge([
                                'from' => $this->clientPhoneNumber,
                                'id' => 'wamid.'.uniqid(),
                                'timestamp' => (string) time(),
                                'type' => $messageType,
                            ], $messageContent),
                        ],
                    ],
                    'field' => 'messages',
                ]],
            ]],
        ];
    }

    public function test_complete_conversation_flow_from_creation(): void
    {
        // Set test phone number in config (used when integration is not in production)
        config(['whatsapp.test_phone_number' => $this->clientPhoneNumber]);
        config(['whatsapp.phone_number_id' => 'TEST_PHONE_ID']);
        config(['whatsapp.api_token' => 'test_token']);
        config(['whatsapp.initial_template_name' => 'retomar_conversacion']);
        config(['whatsapp.initial_template_language' => 'en']);

        // ============================================
        // STEP 1: Create conversation (triggers template send)
        // ============================================
        $conversation = Conversation::create([
            'company_id' => $this->company->id,
            'phone_number' => $this->clientPhoneNumber,
            'client_name' => 'Test Client',
            'status' => ConversationStatus::NEW_CONVERSATION,
        ]);

        // Verify template message was created with reconstructed body
        $templateMessage = \App\Models\Message::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('type', 'template')
            ->first();

        $this->assertNotNull($templateMessage);
        $this->assertStringContainsString('Tenemos un mensaje para ti', $templateMessage->body);
        $this->assertEquals('retomar_conversacion', $templateMessage->metadata['template_name'] ?? null);

        // Verify HTTP request was sent to WhatsApp for template
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/messages')
                && $request['type'] === 'template'
                && $request['template']['name'] === 'retomar_conversacion'
                && $request['template']['language']['code'] === 'en'
                && $request['template']['components'][0]['type'] === 'body'
                && $request['template']['components'][0]['parameters'][0]['text'] === 'Queremos ponernos en contacto contigo.';
        });

        // ============================================
        // STEP 2: Client responds via webhook
        // ============================================
        $response = $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            'Hola, quiero hacer un pedido'
        ));

        $response->assertStatus(200)
            ->assertJson(['status' => 'received']);

        // Verify inbound message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Hola, quiero hacer un pedido',
        ]);

        // Verify conversation status changed to RECEIVED
        $conversation->refresh();
        $this->assertEquals(ConversationStatus::RECEIVED, $conversation->status);
        $this->assertNotNull($conversation->last_message_at);

        // Verify admin was notified (database notification)
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'notifiable_type' => User::class,
        ]);

        // ============================================
        // STEP 3: Operator responds (outbound text message)
        // ============================================
        CreateConversationMessageAction::execute([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Hola! Claro, con gusto te ayudo. ¿Qué te gustaría ordenar?',
        ]);

        // Verify outbound message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Hola! Claro, con gusto te ayudo. ¿Qué te gustaría ordenar?',
        ]);

        // Verify HTTP request was sent to WhatsApp for text message
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/messages')
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hola! Claro, con gusto te ayudo. ¿Qué te gustaría ordenar?';
        });

        // ============================================
        // STEP 4: Client responds again via webhook
        // ============================================
        $response = $this->postJson(route('v1.whatsapp.webhook'), $this->buildWebhookPayload(
            'Quiero 5 ensaladas para mañana'
        ));

        $response->assertStatus(200);

        // Verify second inbound message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Quiero 5 ensaladas para mañana',
        ]);

        // Verify last_message_at was updated
        $previousLastMessageAt = $conversation->last_message_at;
        $conversation->refresh();
        $this->assertGreaterThanOrEqual($previousLastMessageAt, $conversation->last_message_at);

        // Verify admin was notified again (2 total database notifications)
        $this->assertEquals(2, $this->adminUser->notifications()->count());

        // ============================================
        // FINAL ASSERTIONS
        // ============================================
        $conversation->refresh();

        // Total messages: 1 template + 2 inbound + 1 outbound text = 4
        $this->assertEquals(4, $conversation->messages()->count());

        // Outbound: 1 template + 1 text
        $this->assertEquals(2, $conversation->messages()->where('direction', 'outbound')->count());
        $this->assertEquals(1, $conversation->messages()->where('type', 'template')->count());
        $this->assertEquals(1, $conversation->messages()->where('direction', 'outbound')->where('type', 'text')->count());

        // Inbound: 2 text messages
        $this->assertEquals(2, $conversation->messages()->where('direction', 'inbound')->count());

        // Verify 2 HTTP requests were sent to WhatsApp (template + text)
        Http::assertSentCount(2);
    }
}
