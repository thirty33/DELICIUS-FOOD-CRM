<?php

namespace App\Filament\Resources\BillingProcessResource\Pages;

use App\Filament\Resources\BillingProcessResource;
use App\Repositories\BillingProcessRepository;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingProcess extends CreateRecord
{
    protected static string $resource = BillingProcessResource::class;

    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('create_and_bill')
                ->label('Crear y Facturar')
                ->icon('heroicon-o-document-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Crear y Procesar Facturación')
                ->modalDescription('¿Deseas crear el proceso de facturación y ejecutarlo inmediatamente?')
                ->action(function () {
                    // Create the record first
                    $this->create();

                    // Get the created record
                    $record = $this->record;

                    // Process billing
                    $repository = new BillingProcessRepository();
                    $result = $repository->processBilling($record);

                    if ($result['success']) {
                        Notification::make()
                            ->title('Facturación procesada')
                            ->body($result['message'])
                            ->success()
                            ->send();

                        return redirect()->route('filament.admin.resources.billing-processes.edit', ['record' => $record->id]);
                    } else {
                        Notification::make()
                            ->title('Proceso creado pero facturación falló')
                            ->body($result['message'])
                            ->warning()
                            ->send();

                        return redirect()->route('filament.admin.resources.billing-processes.edit', ['record' => $record->id]);
                    }
                }),
        ];
    }
}
