<?php

namespace App\Actions\Conversations;

use App\Actions\Contracts\CreateAction;
use App\Enums\ConversationStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Conversation;

final class CreateConversationAction implements CreateAction
{
    public static function execute(array $data = []): Conversation
    {
        $sourceType = data_get($data, 'source_type');
        $companyId = data_get($data, 'company_id');
        $branchId = data_get($data, 'branch_id');
        $withoutEvents = data_get($data, 'without_events', false);

        $phone = null;
        $clientName = null;

        if ($sourceType === 'company' && $companyId) {
            $company = Company::findOrFail($companyId);
            $phone = $company->routeNotificationForWhatsApp();
            $clientName = $company->fantasy_name ?: $company->name;
        }

        if ($sourceType === 'branch' && $branchId) {
            $branch = Branch::findOrFail($branchId);
            $phone = $branch->routeNotificationForWhatsApp();
            $clientName = $branch->fantasy_name ?: $branch->contact_name;
        }

        if (! $phone) {
            throw new \InvalidArgumentException(__('La selección no tiene un número de teléfono registrado.'));
        }

        $attributes = ['phone_number' => $phone];
        $values = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'client_name' => $clientName,
            'status' => ConversationStatus::NEW_CONVERSATION,
            'last_message_at' => now(),
        ];

        if ($withoutEvents) {
            return Conversation::withoutEvents(fn () => Conversation::firstOrCreate($attributes, $values));
        }

        return Conversation::firstOrCreate($attributes, $values);
    }
}