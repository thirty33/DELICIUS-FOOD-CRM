<?php

namespace App\Channels;

use App\Enums\IntegrationName;
use App\Models\Integration;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class WhatsAppChannel
{
    public function send(object $notifiable, Notification $notification)
    {
        Log::info('WhatsAppChannel: send() called', [
            'notifiable' => get_class($notifiable) . '#' . $notifiable->getKey(),
            'notification' => get_class($notification),
        ]);

        $message = $notification->toWhatsApp($notifiable);
        $to = $notifiable->routeNotificationFor('whatsapp');

        Log::info('WhatsAppChannel: routing', [
            'to' => $to,
        ]);

        $integration = Integration::where('name', IntegrationName::WHATSAPP)
            ->where('active', true)
            ->first();

        if (!$integration) {
            Log::warning('WhatsAppChannel: no active WhatsApp integration found');
            return null;
        }

        $baseUrl = $integration->production ? $integration->url : $integration->url_test;

        $endpoint = sprintf(
            '%s/%s/messages',
            $baseUrl,
            config('whatsapp.phone_number_id'),
        );

        $data = array_merge(
            ['to' => sprintf('+%s', $to)],
            $message->toArray()
        );

        Log::info('WhatsAppChannel: sending request', [
            'endpoint' => $endpoint,
            'data' => $data,
        ]);

        $request = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->withToken(config('whatsapp.api_token'))
            ->post($endpoint, $data);

        $response = $request->json();

        Log::info('WhatsAppChannel: response', [
            'status' => $request->status(),
            'body' => $response,
        ]);

        return $response;
    }
}
