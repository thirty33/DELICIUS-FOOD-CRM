<?php

namespace App\Services\Reminders\Strategies;

use App\Enums\CampaignEventType;
use App\Models\Campaign;
use App\Models\CampaignTrigger;
use App\Repositories\MenuRepository;
use App\Repositories\OrderRepository;
use App\Services\Reminders\Contracts\ReminderEventStrategy;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MenuClosingStrategy implements ReminderEventStrategy
{
    public function __construct(
        private MenuRepository $menuRepository,
        private OrderRepository $orderRepository,
    ) {}

    public function getEventType(): CampaignEventType
    {
        return CampaignEventType::MENU_CLOSING;
    }

    public function getHoursField(): string
    {
        return 'hours_before';
    }

    public function getEligibleEntities(CampaignTrigger $trigger, array $roleIds, array $permissionIds): Collection
    {
        $hoursBefore = $trigger->hours_before ?? 3;

        if (config('reminders.test_mode')) {
            $closingBefore = now()->addMinutes(config('reminders.test_mode_lookback_minutes', 10));
        } else {
            $closingBefore = now()->addHours($hoursBefore);
        }

        return $this->menuRepository->getMenusClosingSoon($closingBefore, $roleIds, $permissionIds);
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
        $templateConfig = config('reminders.templates.menu_closing');

        $fechaPedido = $this->formatMenuDate($entities->min('publication_date'));
        $paginaWeb = config('reminders.shop_url');

        return [
            'name' => $templateConfig['name'],
            'language' => $templateConfig['language'],
            'body' => "¡Aún no tienes pedido para mañana!\n\n"
                ."Hola, notamos que aún no has realizado tu pedido para el día {$fechaPedido}. "
                ."El menú ya está disponible en {$paginaWeb}. "
                ."¡No te quedes sin tu pedido! \xF0\x9F\x98\x8A\n\n"
                .'Responde AYUDA para consultas o SALIR para no recibir más.',
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'parameter_name' => 'fecha_pedido',
                            'text' => $fechaPedido,
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

    public function shouldNotifyRecipient(array $recipient, Collection $entities): bool
    {
        $publicationDates = $entities->pluck('publication_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        if ($recipient['source_type'] === 'branch' && $recipient['branch_id']) {
            return ! $this->orderRepository->hasOrderForMenuDates(
                $recipient['branch_id'],
                $publicationDates
            );
        }

        return ! $this->orderRepository->hasOrderForCompanyOnDates(
            $recipient['company_id'],
            $publicationDates
        );
    }

    private function formatMenuDate(?string $date): string
    {
        if (! $date) {
            return '';
        }

        return Carbon::parse($date)->translatedFormat('l j \\d\\e F');
    }
}
