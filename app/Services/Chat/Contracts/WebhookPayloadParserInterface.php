<?php

namespace App\Services\Chat\Contracts;

interface WebhookPayloadParserInterface
{
    /**
     * Parse the webhook payload and return an array of incoming messages.
     *
     * Each message is an array with keys:
     * - from: string (phone number of sender)
     * - contact_name: string|null (profile name of sender)
     * - message_id: string (whatsapp message id)
     * - type: string (text, image, etc.)
     * - body: string|null (message content)
     *
     * @param array $payload
     * @return array<int, array{from: string, contact_name: ?string, message_id: string, type: string, body: ?string}>
     */
    public function parse(array $payload): array;
}
