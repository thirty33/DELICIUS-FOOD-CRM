<?php

namespace App\Notifications\WhatsApp;

use App\Channels\Messages\WhatsApp\LocationMessage;
use App\Channels\Messages\WhatsApp\Types\Location;
use App\Channels\Messages\WhatsApp\WhatsAppMessage;
use App\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LocationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly string $name,
        private readonly string $address,
    )
    {}

    public function via(object $notifiable): array
    {
        return [WhatsAppChannel::class];
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $location = new Location(
            $this->latitude,
            $this->longitude,
            $this->name,
            $this->address,
        );

        return (new LocationMessage($location));
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}