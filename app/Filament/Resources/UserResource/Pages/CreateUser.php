<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Filament\Forms;
use Filament\Forms\Form;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;


    // Método antes de crear el formulario
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        \Log::info('CreateUser::mutateFormDataBeforeCreate - raw data', [
            'keys' => array_keys($data),
            'has_plain_password_input' => isset($data['plain_password_input']),
            'plain_password_input_length' => isset($data['plain_password_input']) ? strlen($data['plain_password_input']) : 0
        ]);
        
        // Usamos el campo oculto para guardar la contraseña en texto plano
        if (isset($data['plain_password_input'])) {
            \Log::info('CreateUser::mutateFormDataBeforeCreate - setting plain_password', [
                'plain_password_length' => strlen($data['plain_password_input'])
            ]);
            
            // Asignar directamente al campo plain_password
            $data['plain_password'] = $data['plain_password_input'];
        }
        
        return $data;
    }

    // Guardar contraseña en texto plano después de crear el registro
    protected function handleRecordCreation(array $data): Model
    {
        \Log::info('CreateUser::handleRecordCreation - data', [
            'has_plain_password' => isset($data['plain_password']),
            'plain_password_length' => isset($data['plain_password']) ? strlen($data['plain_password']) : 0,
            'has_plain_password_input' => isset($data['plain_password_input']),
            'plain_password_input_length' => isset($data['plain_password_input']) ? strlen($data['plain_password_input']) : 0
        ]);
        
        // Verificamos si tenemos plain_password desde el campo oculto
        $plainPasswordFromInput = $data['plain_password_input'] ?? null;
        
        if ($plainPasswordFromInput) {
            \Log::info('CreateUser::handleRecordCreation - using plain_password from input field', [
                'length' => strlen($plainPasswordFromInput)
            ]);
        }
        
        // Crear el registro usando el método padre
        $record = parent::handleRecordCreation($data);
        
        \Log::info('CreateUser::handleRecordCreation - record created', [
            'user_id' => $record->id
        ]);
        
        // Si tenemos la contraseña desde el campo oculto, volvemos a guardarla para asegurarnos
        if ($plainPasswordFromInput) {
            \Log::info('CreateUser::handleRecordCreation - saving plain password directly to DB', [
                'user_id' => $record->id,
                'password_length' => strlen($plainPasswordFromInput)
            ]);
            
            try {
                // Guardamos directamente en la base de datos
                $updated = \DB::table('users')
                    ->where('id', $record->id)
                    ->update(['plain_password' => $plainPasswordFromInput]);
                
                \Log::info('CreateUser::handleRecordCreation - DB update result', [
                    'updated_records' => $updated
                ]);
                
                // Verificamos que se guardó correctamente
                $checkPlainPassword = \DB::table('users')
                    ->where('id', $record->id)
                    ->value('plain_password');
                
                \Log::info('CreateUser::handleRecordCreation - verification', [
                    'saved' => !empty($checkPlainPassword),
                    'length' => $checkPlainPassword ? strlen($checkPlainPassword) : 0,
                    'is_hash' => $checkPlainPassword ? (str_starts_with($checkPlainPassword, '$2y$') || 
                                                        str_starts_with($checkPlainPassword, '$2a$')) : false
                ]);
            } catch (\Exception $e) {
                \Log::error('CreateUser::handleRecordCreation - error saving plain password', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $record;
    }
}