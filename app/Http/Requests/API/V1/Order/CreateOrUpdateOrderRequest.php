<?php

namespace App\Http\Requests\API\V1\Order;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Menu;

class CreateOrUpdateOrderRequest extends FormRequest
{
    public function rules()
    {
        return [
            'date' => $this->route('date') ? 'date_format:Y-m-d' : 'required|date_format:Y-m-d',
            'order_lines' => 'required|array',
            'order_lines.*.id' => 'required|integer|exists:products,id',
            'order_lines.*.quantity' => 'required|integer|min:1',
            'order_lines.*.partially_scheduled' => 'sometimes|boolean',
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
            'date.required' => 'The date field is required.',
            'date.date_format' => 'The date must be in the format yyyy-mm-dd.',
            'order_lines.required' => 'The order_lines field is required.',
            'order_lines.array' => 'The order_lines must be an array.',
            'order_lines.*.id.required' => 'The id field is required for each order line.',
            'order_lines.*.id.integer' => 'The id field must be an integer.',
            'order_lines.*.id.exists' => 'The specified order line does not exist.',
            'order_lines.*.quantity.required' => 'The quantity field is required for each order line.',
            'order_lines.*.quantity.integer' => 'The quantity field must be an integer.',
            'order_lines.*.quantity.min' => 'The quantity field must be at least 1.',
            'order_lines.*.partially_scheduled.boolean' => 'The partially_scheduled field must be a boolean.'
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
