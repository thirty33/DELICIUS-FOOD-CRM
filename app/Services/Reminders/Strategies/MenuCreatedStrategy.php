<?php

namespace App\Services\Reminders\Strategies;

use App\Enums\CampaignEventType;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Repositories\MenuRepository;
use App\Services\Reminders\Contracts\ReminderEventStrategy;
use Illuminate\Support\Collection;

class MenuCreatedStrategy implements ReminderEventStrategy
{
    public function __construct(
        private MenuRepository $menuRepository
    ) {}

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
        $hoursAfter = $trigger->hours_after ?? 0;

        if (config('reminders.test_mode')) {
            $since = now()->subMinutes(config('reminders.test_mode_lookback_minutes', 10));
        } else {
            $since = now()->subHours($hoursAfter);
        }

        return $this->menuRepository->getMenusCreatedSince($since, $roleIds, $permissionIds);
    }

    public function buildMessageContent(Campaign $campaign, Collection $entities): string
    {
        $content = $campaign->content ?? '';

        return str_replace(
            ['{{menu_count}}', '{{menus}}'],
            [$entities->count(), $entities->pluck('title')->join(', ')],
            $content
        );
    }

    public function getTemplateConfig(Campaign $campaign, Collection $entities): array
    {
        $templateConfig = config('reminders.templates.menu_created');

        $fechaInicio = $this->formatMenuDate($entities->min('publication_date'));
        $fechaFin = $this->formatMenuDate($entities->max('publication_date'));
        $paginaWeb = config('reminders.shop_url');

        return [
            'name' => $templateConfig['name'],
            'language' => $templateConfig['language'],
            'body' => "¡Nuevos menús disponibles!\n\n"
                ."Hola, se han creado menús nuevos para la semana del {$fechaInicio} al {$fechaFin}. "
                ."Ve a {$paginaWeb} y realiza tu pedido \xF0\x9F\x9B\x92\n\n"
                .'Responde AYUDA para consultas o SALIR para no recibir más.',
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'parameter_name' => 'fecha_inicio',
                            'text' => $fechaInicio,
                        ],
                        [
                            'type' => 'text',
                            'parameter_name' => 'fecha_fin',
                            'text' => $fechaFin,
                        ],
                        [
                            'type' => 'text',
                            'parameter_name' => 'pagina_web',
                            'text' => $paginaWeb,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function formatMenuDate(?string $date): string
    {
        if (! $date) {
            return '';
        }

        return \Carbon\Carbon::parse($date)->translatedFormat('l j \\d\\e F');
    }
}
