<?php

namespace App\Http\Requests\API\V1\Order;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\OrderStatus;
use App\Enums\TimePeriod;

class OrderListRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Filtros existentes
            'order_status' => 'nullable|in:' . implode(',', OrderStatus::getValues()),
            'time_period' => 'nullable|in:' . implode(',', TimePeriod::getValues()),
            
            // Búsqueda
            'user_search' => 'nullable|string|max:255',
            'branch_search' => 'nullable|string|max:255',
            
            // Ordenamiento
            'sort_column' => 'nullable|string|in:id,dispatch_date,status,total,created_at,updated_at',
            'sort_direction' => 'nullable|string|in:asc,desc',
            
            // Paginación
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Mensajes para filtros existentes
            'order_status.in' => 'El estado de la orden debe ser uno de los siguientes valores: ' . implode(', ', OrderStatus::getValues()),
            'time_period.in' => 'El período de tiempo debe ser uno de los siguientes valores: this_week, this_month, last_3_months, last_6_months, this_year.',
            
            // Mensajes para búsqueda
            'user_search.string' => 'El término de búsqueda de usuario debe ser un texto.',
            'user_search.max' => 'El término de búsqueda de usuario no puede exceder los 255 caracteres.',
            'branch_search.string' => 'El término de búsqueda de sucursal debe ser un texto.',
            'branch_search.max' => 'El término de búsqueda de sucursal no puede exceder los 255 caracteres.',
            
            // Mensajes para ordenamiento
            'sort_column.in' => 'La columna de ordenamiento debe ser una de las siguientes: id, dispatch_date, status, total, created_at, updated_at.',
            'sort_direction.in' => 'La dirección de ordenamiento debe ser "asc" o "desc".',
            
            // Mensajes para paginación
            'per_page.integer' => 'El número de elementos por página debe ser un número entero.',
            'per_page.min' => 'El número de elementos por página debe ser al menos 1.',
            'per_page.max' => 'El número de elementos por página no puede exceder 100.',
        ];
    }
}