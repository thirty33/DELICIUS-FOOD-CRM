<?php

namespace Tests\Feature\Chat;

use App\Enums\ConversationStatus;
use App\Enums\IntegrationName;
use App\Enums\IntegrationType;
use App\Enums\WindowStatus;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Integration;
use App\Models\Message;
use App\Models\PriceList;
use App\Services\Conversations\ConversationWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ConversationWindowServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversationWindowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);

        $this->service = app(ConversationWindowService::class);
    }

    private function createConversation(array $overrides = []): Conversation
    {
        return Conversation::create(array_merge([
            'phone_number' => '560000000001',
            'client_name' => 'Test Client',
            'status' => ConversationStatus::NEW_CONVERSATION,
        ], $overrides));
    }

    public function test_window_status_is_awaiting_response_when_no_inbound_and_no_expiration(): void
    {
        $conversation = $this->createConversation();

        $status = $this->service->getWindowStatus($conversation);

        $this->assertEquals(WindowStatus::AwaitingResponse, $status);
    }

    public function test_window_status_is_active_when_window_not_expired(): void
    {
        $conversation = $this->createConversation([
            'window_expires_at' => now()->addHours(12),
        ]);

        $status = $this->service->getWindowStatus($conversation);

        $this->assertEquals(WindowStatus::Active, $status);
    }

    public function test_window_status_is_expired_when_window_in_past(): void
    {
        $conversation = $this->createConversation([
            'window_expires_at' => now()->subHour(),
        ]);

        $status = $this->service->getWindowStatus($conversation);

        $this->assertEquals(WindowStatus::Expired, $status);
    }

    public function test_window_status_is_expired_when_has_inbound_but_no_expiration(): void
    {
        $conversation = $this->createConversation();

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Hello',
        ]);

        $status = $this->service->getWindowStatus($conversation);

        $this->assertEquals(WindowStatus::Expired, $status);
    }

    public function test_text_message_allowed_only_when_active(): void
    {
        // Active window
        $active = $this->createConversation([
            'phone_number' => '560000000010',
            'window_expires_at' => now()->addHours(12),
        ]);
        $this->assertTrue($this->service->isTextMessageAllowed($active));

        // Expired window
        $expired = $this->createConversation([
            'phone_number' => '560000000011',
            'window_expires_at' => now()->subHour(),
        ]);
        $this->assertFalse($this->service->isTextMessageAllowed($expired));

        // Awaiting response (no window, no inbound)
        $awaiting = $this->createConversation([
            'phone_number' => '560000000012',
        ]);
        $this->assertFalse($this->service->isTextMessageAllowed($awaiting));
    }

    public function test_get_window_expires_at_returns_null_when_expired(): void
    {
        $conversation = $this->createConversation([
            'window_expires_at' => now()->subHour(),
        ]);

        $this->assertNull($this->service->getWindowExpiresAt($conversation));
    }

    public function test_get_window_expires_at_returns_date_when_active(): void
    {
        $expiresAt = now()->addHours(12);

        $conversation = $this->createConversation([
            'window_expires_at' => $expiresAt,
        ]);

        $result = $this->service->getWindowExpiresAt($conversation);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta($expiresAt->timestamp, $result->timestamp, 2);
    }

    public function test_resend_template_creates_message_and_sends_notification(): void
    {
        Notification::fake();

        Integration::create([
            'name' => IntegrationName::WHATSAPP,
            'url' => 'https://graph.facebook.com/v24.0',
            'url_test' => 'https://graph.facebook.com/v24.0',
            'type' => IntegrationType::MESSAGING,
            'production' => false,
            'active' => true,
        ]);

        config(['whatsapp.test_phone_number' => '560000000001']);
        config(['whatsapp.initial_template_name' => 'retomar_conversacion']);
        config(['whatsapp.initial_template_language' => 'en']);

        $priceList = PriceList::create(['name' => 'Test PL']);

        $company = Company::factory()->create([
            'phone_number' => '560000000001',
            'price_list_id' => $priceList->id,
        ]);

        $conversation = $this->createConversation([
            'company_id' => $company->id,
        ]);

        $message = $this->service->resendTemplate($conversation);

        $this->assertNotNull($message);
        $this->assertEquals('outbound', $message->direction);
        $this->assertEquals('template', $message->type);
        $this->assertStringContainsString('Tenemos un mensaje para ti', $message->body);
        $this->assertEquals('retomar_conversacion', $message->metadata['template_name']);

        Notification::assertSentTo(
            $company,
            \App\Notifications\WhatsApp\TemplateNotification::class
        );
    }
}
