<?php

namespace App\Filament\Resources\MenuResource\Pages;

use App\Filament\Resources\MenuResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Models\Menu;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class EditMenu extends EditRecord
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            // Construir la consulta para búsqueda de duplicados
            $query = Menu::query()
                ->where('publication_date', $data['publication_date'])
                ->where('role_id', $data['rol'])
                ->where('permissions_id', $data['permission'])
                ->where('active', $data['active'])
                ->where('id', '!=', $record->id);

            // Verificar duplicados
            $duplicateMenu = $query->exists();

            if ($duplicateMenu) {
                Notification::make()
                    ->title('Error')
                    ->body('Ya existe un menú con la misma combinación de Fecha de despacho, Tipo de usuario, Tipo de Convenio y estado Activo')
                    ->danger()
                    ->persistent()
                    ->send();

                // Lanzar una excepción de validación
                throw ValidationException::withMessages([
                    'publication_date' => __('Ya existe un menú con la misma combinación de Fecha de despacho, Tipo de usuario, Tipo de Convenio y estado Activo.'),
                ]);
            }

            // Si no hay duplicados, continuar con la actualización normal
            $record->update($data);
            return $record;
        } catch (ValidationException $e) {
            // Reenviar la excepción de validación
            throw $e;
        } catch (\Exception $e) {
            // Capturar cualquier otra excepción inesperada
            throw $e;
        }
    }
}
