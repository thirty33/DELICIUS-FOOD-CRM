<?php

namespace App\Services\Chat;

use App\Actions\Conversations\CreateConversationAction;
use App\Actions\Conversations\UpdateConversationStatusAction;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Repositories\ConversationRepository;
use App\Repositories\PhoneNumberRepository;
use Illuminate\Support\Facades\Log;

class ProcessWebhookService
{
    public function __construct(
        private ConversationRepository $conversationRepository,
        private PhoneNumberRepository $phoneNumberRepository,
        private IncomingMessageService $incomingMessageService,
    ) {}

    public function process(array $payload): void
    {
        $parser = new WebhookPayloadParserV24;
        $messages = $parser->parse($payload);

        foreach ($messages as $msg) {
            $conversation = $this->conversationRepository->findActiveByPhoneNumber($msg['from']);

            if (! $conversation) {
                $conversation = $this->createConversationFromInbound($msg);
            }

            if (! $conversation) {
                Log::warning('Could not create conversation for inbound message', [
                    'from' => $msg['from'],
                ]);

                continue;
            }

            UpdateConversationStatusAction::execute([
                'conversation_id' => $conversation->id,
                'status' => ConversationStatus::RECEIVED,
            ]);

            $this->incomingMessageService->handle(
                $conversation->id,
                $msg['body'] ?? '',
                $msg['type']
            );
        }
    }

    private function createConversationFromInbound(array $msg): ?Conversation
    {
        $owner = $this->phoneNumberRepository->resolveOwner($msg['from']);

        if ($owner) {
            return CreateConversationAction::execute([
                'source_type' => $owner['source_type'],
                'company_id' => $owner['company_id'],
                'branch_id' => $owner['branch_id'],
                'without_events' => true,
            ]);
        }

        return CreateConversationAction::execute([
            'source_type' => 'unknown',
            'phone_number' => $msg['from'],
            'client_name' => $msg['contact_name'] ?? null,
            'without_events' => true,
        ]);
    }
}
