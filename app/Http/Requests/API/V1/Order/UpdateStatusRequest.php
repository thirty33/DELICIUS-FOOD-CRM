<?php

namespace App\Http\Requests\API\V1\Order;

use App\Http\Requests\API\V1\Menu\DelegateUserRequest;
use App\Classes\Menus\MenuHelper;

class UpdateStatusRequest extends DelegateUserRequest
{
    public function rules(): array
    {
        // Merge parent rules with our specific rules
        return array_merge(parent::rules(), [
            'date' => $this->route('date') ? 'date_format:Y-m-d' : 'required|date_format:Y-m-d',
            'status' => 'required|string',
        ]);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'date' => $this->route('date')
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'status.required' => 'The status field is required.',
            'status.string' => 'The status field must be a string.',
        ]);
    }

    public function authorize(): bool
    {
        // Execute parent authorization (delegate user validation)
        // This will throw HttpResponseException if validation fails
        parent::authorize();
        
        // Obtener la fecha del request
        $date = $this->input('date');

        // Obtener el usuario autenticado
        $user = $this->user();

        // Obtener el primer rol del usuario
        $firstRole = $user->roles->first();
        $roleId = $firstRole ? $firstRole->id : null;

        // Obtener el primer permiso del usuario
        $firstPermission = $user->permissions->first();
        $permissionId = $firstPermission ? $firstPermission->id : null;

        // Verificar si existe un menú que cumpla con las condiciones
        $menuExists = MenuHelper::menuExistsForStatusUpdate($date, $roleId, $permissionId);

        // Si no existe un menú válido, denegar la autorización
        if (!$menuExists) {
            return false;
        }

        return true;
    }
}
