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
            \Log::info('CreateMenu: Starting duplicate check', [
                'data' => $data
            ]);

            // Verificar duplicados usando MenuHelper
            $duplicateMenu = \App\Classes\Menus\MenuHelper::checkDuplicateMenuForCreate(
                $data['publication_date'],
                $data['rol'],
                $data['permission'],
                $data['active'],
                [] // Sin empresas por ahora en create
            );

            \Log::info('CreateMenu: Duplicate check completed', [
                'duplicate_found' => $duplicateMenu
            ]);

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
