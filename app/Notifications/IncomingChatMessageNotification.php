<?php

namespace App\Notifications;

use App\Models\Message;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Notifications\Actions\Action as FilamentNotificationAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class IncomingChatMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Message $message,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $conversation = $this->message->conversation;

        return FilamentNotification::make()
            ->title($conversation->client_name ?? $conversation->phone_number)
            ->body(Str::limit($this->message->body, 60))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->actions([
                FilamentNotificationAction::make('view')
                    ->label('Abrir chat')
                    ->url('/chat/' . $conversation->id),
            ])
            ->getDatabaseMessage();
    }
}