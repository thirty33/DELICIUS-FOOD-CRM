<?php

namespace App\Services\Conversations;

use App\Actions\Conversations\CreateConversationMessageAction;
use App\Enums\WindowStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\WhatsApp\TemplateNotification;
use App\Repositories\ConversationRepository;
use App\Services\Reminders\Strategies\InitialTemplateStrategy;
use Carbon\Carbon;

final class ConversationWindowService
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private InitialTemplateStrategy $strategy,
    ) {}

    public function getWindowStatus(Conversation $conversation): WindowStatus
    {
        if (! $conversation->window_expires_at) {
            return $this->conversationRepository->hasInboundMessages($conversation)
                ? WindowStatus::Expired
                : WindowStatus::AwaitingResponse;
        }

        if ($conversation->window_expires_at->isPast()) {
            return WindowStatus::Expired;
        }

        return WindowStatus::Active;
    }

    public function isTextMessageAllowed(Conversation $conversation): bool
    {
        return $this->getWindowStatus($conversation) === WindowStatus::Active;
    }

    public function getWindowExpiresAt(Conversation $conversation): ?Carbon
    {
        if (! $conversation->window_expires_at || $conversation->window_expires_at->isPast()) {
            return null;
        }

        return $conversation->window_expires_at;
    }

    public function resendTemplate(Conversation $conversation): Message
    {
        $notifiable = $conversation->company ?? $conversation->branch;

        if (! $notifiable) {
            throw new \RuntimeException('Conversation has no notifiable entity.');
        }

        $templateConfig = $this->strategy->getTemplateConfig();

        $message = CreateConversationMessageAction::execute([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'template',
            'body' => $templateConfig['body'],
            'metadata' => ['template_name' => $templateConfig['name']],
        ]);

        $notifiable->notify(new TemplateNotification(
            $templateConfig['name'],
            $templateConfig['language'],
            $templateConfig['components'],
            $message->id,
        ));

        return $message;
    }
}
