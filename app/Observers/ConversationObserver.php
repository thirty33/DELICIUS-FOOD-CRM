<?php

namespace App\Observers;

use App\Actions\Conversations\CreateConversationMessageAction;
use App\Models\Conversation;
use App\Notifications\WhatsApp\TemplateNotification;

class ConversationObserver
{
    public function created(Conversation $conversation): void
    {
        $notifiable = $conversation->company ?? $conversation->branch;

        if (!$notifiable) {
            return;
        }

        $templateName = config('whatsapp.initial_template_name');

        $notifiable->notify(new TemplateNotification($templateName));

        CreateConversationMessageAction::execute([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'template',
            'body' => $templateName,
        ]);
    }
}