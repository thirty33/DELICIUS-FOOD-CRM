<?php

namespace Tests\Feature\Chat;

use App\Actions\Conversations\CreateConversationAction;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateConversationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_conversation_with_unknown_source_type(): void
    {
        $conversation = CreateConversationAction::execute([
            'source_type' => 'unknown',
            'phone_number' => '560000099999',
            'client_name' => 'Unknown Person',
            'without_events' => true,
        ]);

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertEquals('560000099999', $conversation->phone_number);
        $this->assertEquals('Unknown Person', $conversation->client_name);
        $this->assertNull($conversation->company_id);
        $this->assertNull($conversation->branch_id);
        $this->assertEquals(ConversationStatus::NEW_CONVERSATION, $conversation->status);
    }

    public function test_unknown_source_requires_phone_number(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CreateConversationAction::execute([
            'source_type' => 'unknown',
            'client_name' => 'No Phone Person',
            'without_events' => true,
        ]);
    }
}
