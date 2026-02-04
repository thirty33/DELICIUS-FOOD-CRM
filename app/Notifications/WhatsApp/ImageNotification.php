<?php

namespace App\Notifications\WhatsApp;

use App\Channels\Messages\WhatsApp\ImageMessage;
use App\Channels\Messages\WhatsApp\Types\Image;
use App\Channels\Messages\WhatsApp\WhatsAppMessage;
use App\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ImageNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $link,
        private readonly string $caption,
    )
    {}

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $image = new Image(
            $this->link,
            $this->caption,
        );

        return (new ImageMessage($image));
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}