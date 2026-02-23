<?php

namespace App\Services\Reminders\Strategies;

use App\Enums\CampaignEventType;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use Illuminate\Support\Collection;

final class InitialTemplateStrategy
{
    public function getEventType(): CampaignEventType
    {
        return CampaignEventType::MENU_CREATED;
    }

    public function getHoursField(): string
    {
        return 'hours_after';
    }

    public function getEligibleEntities(CampaignTrigger $trigger, array $roleIds, array $permissionIds): Collection
    {
        return collect();
    }

    public function buildMessageContent(Campaign $campaign, Collection $entities): string
    {
        return '';
    }

    public function getTemplateConfig(?Campaign $campaign = null, ?Collection $entities = null): array
    {
        return [
            'name' => config('whatsapp.initial_template_name'),
            'language' => config('whatsapp.initial_template_language', 'en'),
            'body' => "Tenemos un mensaje para ti\n\n"
                .'Hola, no pudimos completar nuestra conversación anterior. '
                .'Queremos ponernos en contacto contigo. '
                .'Si necesitas ayuda o tienes alguna consulta, responde a este mensaje '
                ."y te atenderemos de inmediato.\n\n"
                .'Responde AYUDA para consultas o SALIR para no recibir más.',
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'parameter_name' => 'motivo_contacto',
                            'text' => 'Queremos ponernos en contacto contigo.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
