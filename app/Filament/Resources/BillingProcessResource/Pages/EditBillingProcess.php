<?php

namespace App\Filament\Resources\BillingProcessResource\Pages;

use App\Filament\Resources\BillingProcessResource;
use App\Repositories\BillingProcessRepository;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBillingProcess extends EditRecord
{
    protected static string $resource = BillingProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('facturar')
                ->label('Facturar')
                ->icon('heroicon-o-document-check')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Procesar Facturación')
                ->modalDescription('¿Estás seguro de que deseas procesar la facturación para este pedido? Se creará un nuevo intento de facturación.')
                ->action(function () {
                    $repository = new BillingProcessRepository();
                    $result = $repository->processBilling($this->record);

                    if ($result['success']) {
                        Notification::make()
                            ->title('Facturación procesada')
                            ->body($result['message'])
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Error al procesar facturación')
                            ->body($result['message'])
                            ->danger()
                            ->send();
                    }
                }),
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }
}
