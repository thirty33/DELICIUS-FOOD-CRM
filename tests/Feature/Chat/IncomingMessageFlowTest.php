<?php

namespace Tests\Feature\Chat;

use App\Enums\RoleName;
use App\Jobs\NotifyAdminsOfIncomingMessage;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\User;
use App\Notifications\IncomingChatMessageNotification;
use App\Services\Chat\IncomingMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Integration test for the incoming message flow.
 *
 * Tests the full chain:
 * IncomingMessageService → ProcessIncomingMessage Job → MessageObserver → NotifyAdminsOfIncomingMessage Job → Notification
 */
class IncomingMessageFlowTest extends TestCase
{
    use RefreshDatabase;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversation = Conversation::create([
            'phone_number' => '56900000000',
            'client_name' => 'Test Client',
        ]);
    }

    public function test_service_dispatches_process_incoming_message_job(): void
    {
        Queue::fake();

        $service = new IncomingMessageService();
        $service->handle($this->conversation->id, 'Hello from test');

        Queue::assertPushed(ProcessIncomingMessage::class, function ($job) {
            return $job->conversationId === $this->conversation->id
                && $job->body === 'Hello from test'
                && $job->type === 'text';
        });
    }

    public function test_process_incoming_message_job_creates_inbound_message(): void
    {
        Queue::fake([NotifyAdminsOfIncomingMessage::class]);

        $job = new ProcessIncomingMessage($this->conversation->id, 'Test message body');
        $job->handle();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Test message body',
            'status' => 'received',
        ]);

        $this->conversation->refresh();
        $this->assertNotNull($this->conversation->last_message_at);
    }

    public function test_observer_dispatches_notification_job_for_inbound_messages(): void
    {
        Queue::fake([NotifyAdminsOfIncomingMessage::class]);

        $this->conversation->messages()->create([
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Inbound message',
            'status' => 'received',
        ]);

        Queue::assertPushed(NotifyAdminsOfIncomingMessage::class);
    }

    public function test_observer_does_not_dispatch_notification_for_outbound_messages(): void
    {
        Queue::fake([NotifyAdminsOfIncomingMessage::class]);

        $this->conversation->messages()->create([
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Outbound message',
            'status' => 'sent',
        ]);

        Queue::assertNotPushed(NotifyAdminsOfIncomingMessage::class);
    }

    public function test_notification_job_sends_database_notification_to_admin_users(): void
    {
        Notification::fake();

        $adminRole = Role::create(['name' => RoleName::ADMIN->value]);
        $cafeRole = Role::create(['name' => RoleName::CAFE->value]);

        $priceList = PriceList::create(['name' => 'Test Price List']);
        $company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'email' => 'company@test.com',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCO',
            'fantasy_name' => 'TEST CO',
            'price_list_id' => $priceList->id,
        ]);

        $adminUser = User::create([
            'name' => 'TEST ADMIN',
            'nickname' => 'TEST.ADMIN',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'active' => true,
        ]);
        $adminUser->roles()->attach($adminRole->id);

        $cafeUser = User::create([
            'name' => 'TEST CAFE',
            'nickname' => 'TEST.CAFE',
            'email' => 'cafe@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'active' => true,
        ]);
        $cafeUser->roles()->attach($cafeRole->id);

        $message = $this->conversation->messages()->create([
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Need 20 salads for tomorrow',
            'status' => 'received',
        ]);

        $job = new NotifyAdminsOfIncomingMessage($message);
        $job->handle();

        Notification::assertSentTo($adminUser, IncomingChatMessageNotification::class);
        Notification::assertNotSentTo($cafeUser, IncomingChatMessageNotification::class);
    }

    public function test_full_flow_from_service_to_notification(): void
    {
        Notification::fake();

        $adminRole = Role::create(['name' => RoleName::ADMIN->value]);

        $priceList = PriceList::create(['name' => 'Test Price List']);
        $company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'email' => 'company@test.com',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCO',
            'fantasy_name' => 'TEST CO',
            'price_list_id' => $priceList->id,
        ]);

        $adminUser = User::create([
            'name' => 'TEST ADMIN',
            'nickname' => 'TEST.ADMIN',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'active' => true,
        ]);
        $adminUser->roles()->attach($adminRole->id);

        // Execute the full chain synchronously
        $job = new ProcessIncomingMessage($this->conversation->id, 'Full flow test message');
        $job->handle();

        // Message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'body' => 'Full flow test message',
        ]);

        // last_message_at was updated
        $this->conversation->refresh();
        $this->assertNotNull($this->conversation->last_message_at);

        // Observer dispatched the notification job, execute it
        $message = Message::where('body', 'Full flow test message')->first();
        $notifyJob = new NotifyAdminsOfIncomingMessage($message);
        $notifyJob->handle();

        // Admin received the notification
        Notification::assertSentTo($adminUser, IncomingChatMessageNotification::class);
    }

    public function test_simulate_command_dispatches_job(): void
    {
        Queue::fake();

        $this->artisan('whatsapp:simulate-reply', [
            'conversation_id' => $this->conversation->id,
            'message' => 'Command test message',
        ])->assertSuccessful();

        Queue::assertPushed(ProcessIncomingMessage::class, function ($job) {
            return $job->conversationId === $this->conversation->id
                && $job->body === 'Command test message';
        });
    }

    public function test_multiple_messages_create_multiple_notifications(): void
    {
        Notification::fake();

        $adminRole = Role::create(['name' => RoleName::ADMIN->value]);

        $priceList = PriceList::create(['name' => 'Test Price List']);
        $company = Company::create([
            'name' => 'TEST COMPANY S.A.',
            'email' => 'company@test.com',
            'tax_id' => '12.345.678-9',
            'company_code' => 'TESTCO',
            'fantasy_name' => 'TEST CO',
            'price_list_id' => $priceList->id,
        ]);

        $adminUser = User::create([
            'name' => 'TEST ADMIN',
            'nickname' => 'TEST.ADMIN',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
            'active' => true,
        ]);
        $adminUser->roles()->attach($adminRole->id);

        // Simulate 3 incoming messages
        $messages = ['First message', 'Second message', 'Third message'];

        foreach ($messages as $text) {
            $job = new ProcessIncomingMessage($this->conversation->id, $text);
            $job->handle();
        }

        $this->assertEquals(3, Message::where('conversation_id', $this->conversation->id)
            ->where('direction', 'inbound')
            ->count());

        // Observer dispatches NotifyAdminsOfIncomingMessage synchronously (no Queue::fake),
        // so notifications are already sent at this point
        Notification::assertSentToTimes($adminUser, IncomingChatMessageNotification::class, 3);
    }
}