<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use App\Jobs\BulkDeleteUsers;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Acción borrar modificada para usar el job de eliminación en segundo plano
            Actions\DeleteAction::make()
                ->hidden(fn() => $this->record->id === auth()->id())
                ->requiresConfirmation()
                ->modalHeading('Eliminar usuario')
                ->modalDescription('¿Estás seguro de que deseas eliminar este usuario? Esta acción no se puede deshacer.')
                ->modalSubmitActionLabel('Sí, eliminar')
                ->action(function () {
                    $record = $this->record;

                    // Verificar si el usuario intenta eliminarse a sí mismo
                    if ($record->id === auth()->id()) {
                        Notification::make()
                            ->title('Error')
                            ->body('No puedes eliminar tu propio usuario.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        Log::info('Iniciando proceso de eliminación de usuario desde el formulario', [
                            'user_id' => $record->id,
                            'user_name' => $record->name
                        ]);

                        // Crear array con el ID del usuario a eliminar
                        $userIdToDelete = [$record->id];

                        // Dispatch el job para eliminar el usuario en segundo plano
                        BulkDeleteUsers::dispatch($userIdToDelete);

                        Notification::make()
                            ->title('Eliminación en proceso')
                            ->body('El usuario será eliminado en segundo plano.')
                            ->success()
                            ->send();

                        Log::info('Job de eliminación de usuario enviado a la cola', [
                            'user_id' => $record->id
                        ]);

                        // Redirigir a la lista de usuarios
                        $this->redirect(UserResource::getUrl('index'));
                    } catch (\Exception $e) {
                        Log::error('Error al preparar eliminación de usuario desde el formulario', [
                            'user_id' => $record->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);

                        Notification::make()
                            ->title('Error')
                            ->body('Ha ocurrido un error al preparar la eliminación del usuario: ' . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
