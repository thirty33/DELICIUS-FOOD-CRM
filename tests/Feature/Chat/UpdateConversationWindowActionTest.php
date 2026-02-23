<?php

namespace Tests\Feature\Chat;

use App\Actions\Conversations\UpdateConversationWindowAction;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateConversationWindowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_window_expires_at_to_24_hours_from_now(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '560000000001',
            'client_name' => 'Test Client',
            'status' => ConversationStatus::NEW_CONVERSATION,
        ]);

        $before = now();

        UpdateConversationWindowAction::execute([
            'conversation_id' => $conversation->id,
        ]);

        $conversation->refresh();

        $this->assertNotNull($conversation->window_expires_at);

        $expectedMin = $before->addHours(24)->timestamp;
        $expectedMax = now()->addHours(24)->timestamp;

        $this->assertGreaterThanOrEqual($expectedMin, $conversation->window_expires_at->timestamp);
        $this->assertLessThanOrEqual($expectedMax, $conversation->window_expires_at->timestamp);
    }

    public function test_resets_window_on_each_inbound_message(): void
    {
        $conversation = Conversation::create([
            'phone_number' => '560000000002',
            'client_name' => 'Test Client',
            'status' => ConversationStatus::RECEIVED,
            'window_expires_at' => now()->addHour(),
        ]);

        $oldExpiry = $conversation->window_expires_at->copy();

        UpdateConversationWindowAction::execute([
            'conversation_id' => $conversation->id,
        ]);

        $conversation->refresh();

        // New expiry should be ~24h from now, much later than the old 1h
        $this->assertGreaterThan(
            $oldExpiry->timestamp,
            $conversation->window_expires_at->timestamp
        );

        $this->assertEqualsWithDelta(
            now()->addHours(24)->timestamp,
            $conversation->window_expires_at->timestamp,
            5
        );
    }
}
