<?php

namespace App\Http\Requests\API\V1\Order;

use App\Http\Requests\API\V1\Menu\DelegateUserRequest;

class CreateOrUpdateOrderRequest extends DelegateUserRequest
{
    public function rules(): array
    {
        // Merge parent rules with our specific rules
        return array_merge(parent::rules(), [
            'date' => $this->route('date') ? 'date_format:Y-m-d' : 'required|date_format:Y-m-d',
            'order_lines' => 'required|array',
            'order_lines.*.id' => 'required|integer|exists:products,id',
            'order_lines.*.quantity' => 'required|integer|min:1',
            'order_lines.*.partially_scheduled' => 'sometimes|boolean',
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
            'date.required' => 'La fecha es requerida.',
            'date.date_format' => 'La fecha debe estar en formato YYYY-MM-DD (año-mes-día).',
            'order_lines.required' => 'Los productos del pedido son requeridos.',
            'order_lines.array' => 'Los productos del pedido deben ser una lista válida.',
            'order_lines.*.id.required' => 'El ID del producto es requerido para cada artículo del pedido.',
            'order_lines.*.id.integer' => 'El ID del producto debe ser un número entero.',
            'order_lines.*.id.exists' => 'El producto seleccionado no existe o no está disponible.',
            'order_lines.*.quantity.required' => 'La cantidad es requerida para cada producto del pedido.',
            'order_lines.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'order_lines.*.quantity.min' => 'La cantidad mínima es 1 unidad por producto.',
            'order_lines.*.partially_scheduled.boolean' => 'El campo de programación parcial debe ser verdadero o falso.'
        ]);
    }

    public function authorize(): bool
    {
        // Execute parent authorization (delegate user validation)
        // This will throw HttpResponseException if validation fails
        parent::authorize();

        // Check if there is a menu that meets the conditions for the effective user
        return $this->authorizeMenuAccess('menuExistsForOrderCreateUpdate');
    }
}
