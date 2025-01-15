<?php

namespace App\Http\Requests\API\V1\Order;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Menu;

class UpdateStatusRequest extends FormRequest
{
    public function rules()
    {
        return [
            'date' => $this->route('date') ? 'date_format:Y-m-d' : 'required|date_format:Y-m-d',
            'status' => 'required|string',
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'date' => $this->route('date')
        ]);
    }

    public function messages()
    {
        return [
            'status.required' => 'The status field is required.',
            'status.string' => 'The status field must be a string.',
        ];
    }

    public function authorize()
    {
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
        $menuExists = Menu::where('publication_date', $date)
            ->where('role_id', $roleId)
            ->where('permissions_id', $permissionId)
            ->exists();

        // Si no existe un menú válido, denegar la autorización
        if (!$menuExists) {
            return false;
        }

        return true;
    }
}
