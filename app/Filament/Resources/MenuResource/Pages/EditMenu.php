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
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use App\Services\MenuCloneService;

class EditMenu extends EditRecord
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('clone_menu')
                ->label('Clonar')
                ->color('primary')
                ->icon('heroicon-o-document-duplicate')
                ->form(MenuResource::getCloneActionForm())
                ->action(fn (array $data) => MenuResource::executeCloneAction($data, $this->record)),
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            // Get companies associated with current menu
            $companyIds = $record->companies()->pluck('companies.id')->toArray();

            // Check for duplicates using MenuHelper
            $duplicateMenu = \App\Classes\Menus\MenuHelper::checkDuplicateMenuForUpdate(
                $data['publication_date'],
                $data['rol'],
                $data['permission'],
                $data['active'],
                $record->id,
                $companyIds
            );

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
