<?php

namespace App\Console\Commands;

use App\Services\Chat\IncomingMessageService;
use Illuminate\Console\Command;

class SimulateWhatsAppReply extends Command
{
    protected $signature = 'whatsapp:simulate-reply {conversation_id} {message?}';

    protected $description = 'Simulate an incoming WhatsApp message for a conversation';

    public function handle(IncomingMessageService $service): int
    {
        $text = $this->argument('message') ?? 'Respuesta simulada #' . rand(1, 999);

        $service->handle((int) $this->argument('conversation_id'), $text);

        $this->info("Job despachado para conversación #{$this->argument('conversation_id')}");

        return self::SUCCESS;
    }
}