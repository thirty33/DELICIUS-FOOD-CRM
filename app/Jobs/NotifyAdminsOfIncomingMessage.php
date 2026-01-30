<?php

namespace App\Jobs;

use App\Enums\RoleName;
use App\Models\Message;
use App\Models\User;
use App\Notifications\IncomingChatMessageNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class NotifyAdminsOfIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Message $message,
    ) {}

    public function handle(): void
    {
        $admins = User::whereHas('roles', fn ($q) => $q->where('name', RoleName::ADMIN->value))
            ->get();

        Notification::send($admins, new IncomingChatMessageNotification($this->message));
    }
}