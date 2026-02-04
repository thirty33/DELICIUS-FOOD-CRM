<?php

namespace App\Services\Chat;

use App\Services\Chat\Contracts\WebhookPayloadParserInterface;

class WebhookPayloadParserV24 implements WebhookPayloadParserInterface
{
    public function parse(array $payload): array
    {
        $parsed = [];
        $entries = data_get($payload, 'entry', []);

        foreach ($entries as $entry) {
            $changes = data_get($entry, 'changes', []);

            foreach ($changes as $change) {
                if (data_get($change, 'field') !== 'messages') {
                    continue;
                }

                $contacts = data_get($change, 'value.contacts', []);
                $contactsByWaId = [];
                foreach ($contacts as $contact) {
                    $waId = data_get($contact, 'wa_id');
                    if ($waId) {
                        $contactsByWaId[$waId] = data_get($contact, 'profile.name');
                    }
                }

                $messages = data_get($change, 'value.messages', []);

                foreach ($messages as $message) {
                    $from = data_get($message, 'from');

                    if (!$from) {
                        continue;
                    }

                    $type = data_get($message, 'type', 'text');

                    $parsed[] = [
                        'from' => $from,
                        'contact_name' => $contactsByWaId[$from] ?? null,
                        'message_id' => data_get($message, 'id'),
                        'type' => $type,
                        'body' => $this->extractBody($message, $type),
                    ];
                }
            }
        }

        return $parsed;
    }

    private function extractBody(array $message, string $type): ?string
    {
        return match ($type) {
            'text' => data_get($message, 'text.body'),
            'image' => data_get($message, 'image.caption'),
            'video' => data_get($message, 'video.caption'),
            'document' => data_get($message, 'document.caption'),
            default => null,
        };
    }
}