<?php

namespace App\Services\Reminders;

use App\Actions\Conversations\CreateConversationAction;
use App\Actions\Conversations\CreateConversationMessageAction;
use App\Actions\Reminders\RecordCampaignExecutionAction;
use App\Actions\Reminders\RecordReminderNotificationAction;
use App\Actions\Reminders\UpdateTriggerLastExecutedAction;
use App\Enums\CampaignEventType;
use App\Enums\CampaignExecutionStatus;
use App\Models\Branch;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Models\Company;
use App\Notifications\WhatsApp\TemplateNotification;
use App\Repositories\CampaignTriggerRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\ReminderNotificationRepository;
use App\Services\Reminders\Contracts\ReminderEventStrategy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProcessRemindersService
{
    public function __construct(
        private CampaignTriggerRepository $triggerRepository,
        private ConversationRepository $conversationRepository,
        private ReminderNotificationRepository $notificationRepository,
    ) {}

    /**
     * Process all active reminders for a specific event type.
     *
     * @return array{triggers_processed: int, sent: int, pending: int, failed: int, skipped: int}
     */
    public function processEventType(CampaignEventType $eventType): array
    {
        $strategy = ReminderStrategyFactory::create($eventType);

        $triggers = $this->triggerRepository->getActiveReminderTriggersByEventType($eventType);

        if ($triggers->isEmpty()) {
            return ['triggers_processed' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $totals = ['triggers_processed' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($triggers as $trigger) {
            $result = $this->processTrigger($trigger, $strategy);
            $totals['triggers_processed']++;
            $totals['sent'] += $result['sent'];
            $totals['pending'] += $result['pending'];
            $totals['failed'] += $result['failed'];
            $totals['skipped'] += $result['skipped'];
        }

        return $totals;
    }

    private function processTrigger(CampaignTrigger $trigger, ReminderEventStrategy $strategy): array
    {
        $campaign = $trigger->campaign;
        $companies = $campaign->companies;

        if ($companies->isEmpty()) {
            return ['sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $roleIds = $this->getRoleIdsFromCompanies($companies);
        $permissionIds = $this->getPermissionIdsFromCompanies($companies);

        $eligibleEntities = $strategy->getEligibleEntities($trigger, $roleIds, $permissionIds);

        if ($eligibleEntities->isEmpty()) {
            return ['sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $recipients = $this->getRecipients($campaign, $companies);

        if ($recipients->isEmpty()) {
            return ['sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $stats = ['sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($recipients as $recipient) {
            $result = $this->processRecipient($trigger, $campaign, $strategy, $recipient, $eligibleEntities);
            $stats[$result]++;
        }

        RecordCampaignExecutionAction::execute([
            'campaign_id' => $campaign->id,
            'trigger_id' => $trigger->id,
            'total_recipients' => $recipients->count(),
            'sent_count' => $stats['sent'],
            'failed_count' => $stats['failed'],
            'status' => $stats['failed'] > 0 ? CampaignExecutionStatus::FAILED->value : CampaignExecutionStatus::COMPLETED->value,
        ]);

        UpdateTriggerLastExecutedAction::execute(['trigger_id' => $trigger->id]);

        return $stats;
    }

    private function processRecipient(
        CampaignTrigger $trigger,
        Campaign $campaign,
        ReminderEventStrategy $strategy,
        array $recipient,
        Collection $eligibleEntities
    ): string {
        $phoneNumber = $recipient['phone_number'];

        $alreadyNotifiedIds = $this->notificationRepository->getNotifiedMenuIds($trigger->id, $phoneNumber);

        $pendingEntities = $eligibleEntities->reject(fn ($menu) => $alreadyNotifiedIds->contains($menu->id));

        if ($pendingEntities->isEmpty()) {
            return 'skipped';
        }

        $templateConfig = $strategy->getTemplateConfig($campaign, $pendingEntities);

        return $this->sendTemplateNotification($trigger, $recipient, $pendingEntities, $templateConfig);
    }

    private function sendTemplateNotification(
        CampaignTrigger $trigger,
        array $recipient,
        Collection $menus,
        array $templateConfig
    ): string {
        try {
            $conversation = $this->conversationRepository->findActiveByPhoneNumber($recipient['phone_number']);

            if (! $conversation) {
                $conversation = CreateConversationAction::execute([
                    'source_type' => $recipient['source_type'],
                    'company_id' => $recipient['company_id'],
                    'branch_id' => $recipient['branch_id'],
                    'without_events' => true,
                ]);
            }

            // Record the message in the conversation
            CreateConversationMessageAction::execute([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => 'template',
                'body' => "Template: {$templateConfig['name']}",
            ]);

            // Send the WhatsApp template notification via the notifiable
            $notifiable = $this->resolveNotifiable($recipient);
            $notifiable->notify(new TemplateNotification(
                $templateConfig['name'],
                $templateConfig['language'],
                $templateConfig['components'],
            ));

            foreach ($menus as $menu) {
                RecordReminderNotificationAction::execute([
                    'trigger_id' => $trigger->id,
                    'menu_id' => $menu->id,
                    'phone_number' => $recipient['phone_number'],
                    'conversation_id' => $conversation->id,
                    'status' => 'sent',
                ]);
            }

            return 'sent';
        } catch (\Exception $e) {
            Log::error('Error sending reminder template', [
                'trigger_id' => $trigger->id,
                'phone_number' => $recipient['phone_number'],
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    private function resolveNotifiable(array $recipient): Company|Branch
    {
        if ($recipient['source_type'] === 'branch' && $recipient['branch_id']) {
            return Branch::findOrFail($recipient['branch_id']);
        }

        return Company::findOrFail($recipient['company_id']);
    }

    /**
     * Build recipient list from campaign companies and branches.
     *
     * @return Collection<int, array{phone_number: string, source_type: string, company_id: int, branch_id: int|null}>
     */
    private function getRecipients(Campaign $campaign, Collection $companies): Collection
    {
        $campaignBranchIds = $campaign->branches->pluck('id');
        $recipients = collect();

        foreach ($companies as $company) {
            $branches = $company->branches;

            if ($campaignBranchIds->isNotEmpty()) {
                $branches = $branches->filter(fn (Branch $branch) => $campaignBranchIds->contains($branch->id));
            }

            if ($branches->isNotEmpty()) {
                foreach ($branches as $branch) {
                    $phone = $branch->routeNotificationForWhatsApp();

                    if ($phone) {
                        $recipients->push([
                            'phone_number' => $phone,
                            'source_type' => 'branch',
                            'company_id' => $company->id,
                            'branch_id' => $branch->id,
                        ]);
                    } else {
                        $phone = $company->routeNotificationForWhatsApp();

                        if ($phone) {
                            $recipients->push([
                                'phone_number' => $phone,
                                'source_type' => 'company',
                                'company_id' => $company->id,
                                'branch_id' => null,
                            ]);
                        }
                    }
                }
            } else {
                $phone = $company->routeNotificationForWhatsApp();

                if ($phone) {
                    $recipients->push([
                        'phone_number' => $phone,
                        'source_type' => 'company',
                        'company_id' => $company->id,
                        'branch_id' => null,
                    ]);
                }
            }
        }

        return $recipients->unique('phone_number')->values();
    }

    private function getRoleIdsFromCompanies(Collection $companies): array
    {
        return $companies
            ->flatMap(fn (Company $company) => $company->users)
            ->flatMap(fn ($user) => $user->roles)
            ->pluck('id')
            ->unique()
            ->toArray();
    }

    private function getPermissionIdsFromCompanies(Collection $companies): array
    {
        return $companies
            ->flatMap(fn (Company $company) => $company->users)
            ->flatMap(fn ($user) => $user->permissions)
            ->pluck('id')
            ->unique()
            ->toArray();
    }
}