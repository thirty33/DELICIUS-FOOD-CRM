<?php

namespace App\Filament\Resources\MenuResource\Pages;

use App\Filament\Resources\MenuResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use App\Models\Menu;

class CreateMenu extends CreateRecord
{
    protected static string $resource = MenuResource::class;


    protected function handleRecordCreation(array $data): Model
    {
        try {
            // Verificar si ya existe un menú con la misma combinación de campos
            $query = Menu::query()
                ->where('publication_date', $data['publication_date'])
                ->where('role_id', $data['rol'])
                ->where('permissions_id', $data['permission'])
                ->where('active', $data['active']);

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

            // Si no hay duplicados, continuar con la creación normal
            return static::getModel()::create($data);
        } catch (ValidationException $e) {
            // Reenviar la excepción de validación
            throw $e;
        } catch (\Exception $e) {
            // Capturar cualquier otra excepción inesperada
            throw $e;
        }
    }
}
