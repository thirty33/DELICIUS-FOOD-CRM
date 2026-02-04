<?php

namespace App\Notifications\WhatsApp;

use App\Channels\Messages\WhatsApp\TextMessage;
use App\Channels\Messages\WhatsApp\Types\Text;
use App\Channels\Messages\WhatsApp\WhatsAppMessage;
use App\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TextNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly bool $previewUrl,
        private readonly string $body
    )
    {}

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $text = new Text(
            $this->previewUrl,
            $this->body,
        );

        return (new TextMessage($text));
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}