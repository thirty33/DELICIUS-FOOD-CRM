<?php

namespace App\Filament\Resources\AdvanceOrderResource\Pages;

use App\Enums\AdvanceOrderStatus;
use App\Events\AdvanceOrderExecuted;
use App\Events\AdvanceOrderCancelled;
use App\Filament\Resources\AdvanceOrderResource;
use App\Repositories\AdvanceOrderRepository;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAdvanceOrder extends EditRecord
{
    protected static string $resource = AdvanceOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AdvanceOrderResource::getHeaderExecuteAction(),
            AdvanceOrderResource::getHeaderCancelAction(),
        ];
    }

    protected function afterSave(): void
    {
        // Check if dates or use_products_in_orders changed
        $shouldReload = $this->record->wasChanged([
            'initial_dispatch_date',
            'final_dispatch_date',
            'use_products_in_orders',
        ]);

        if ($shouldReload) {
            // Redirect to same page to reload products
            $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->record]));
        }
    }
}
