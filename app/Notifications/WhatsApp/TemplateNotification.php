<?php

namespace App\Notifications\WhatsApp;

use App\Channels\Messages\WhatsApp\TemplateMessage;
use App\Channels\Messages\WhatsApp\Types\Template;
use App\Channels\Messages\WhatsApp\WhatsAppMessage;
use App\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TemplateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $template)
    {}

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $template = new Template($this->template);

        return (new TemplateMessage($template));
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}