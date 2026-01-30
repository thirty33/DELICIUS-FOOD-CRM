<?php

namespace App\Filament\Pages;

use App\Actions\Conversations\CreateConversationMessageAction;
use App\Actions\Conversations\UpdateConversationStatusAction;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Message;
use Filament\Pages\Page;

class ChatPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $title = 'Chat';

    protected static ?string $slug = 'chat/{conversationId}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.chat-page';

    public int $conversationId;

    public string $newMessage = '';

    public $messages = [];

    public ?Conversation $conversation = null;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->conversation = Conversation::findOrFail($conversationId);
        $this->loadMessages();
    }

    public function getTitle(): string
    {
        return $this->conversation?->client_name ?? 'Chat';
    }

    public function loadMessages(): void
    {
        $this->messages = Message::where('conversation_id', $this->conversationId)
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    public function sendMessage(): void
    {
        if (trim($this->newMessage) === '') {
            return;
        }

        CreateConversationMessageAction::execute([
            'conversation_id' => $this->conversationId,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $this->newMessage,
        ]);

        UpdateConversationStatusAction::execute([
            'conversation_id' => $this->conversationId,
            'status' => ConversationStatus::AWAITING_REPLY,
        ]);

        $this->newMessage = '';
        $this->loadMessages();
    }
}