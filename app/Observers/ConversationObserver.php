<?php

namespace App\Observers;

use App\Actions\Conversations\CreateConversationMessageAction;
use App\Models\Conversation;
use App\Notifications\WhatsApp\TemplateNotification;
use App\Services\Reminders\Strategies\InitialTemplateStrategy;

class ConversationObserver
{
    public function __construct(private InitialTemplateStrategy $strategy) {}

    public function created(Conversation $conversation): void
    {
        $notifiable = $conversation->company ?? $conversation->branch;

        if (! $notifiable) {
            return;
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
    }
}
