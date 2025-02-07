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
            'order_status' => 'nullable|in:' . implode(',', OrderStatus::getValues()),
            'time_period' => 'nullable|in:' . implode(',', TimePeriod::getValues()),
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
            'order_status.in' => 'El campo order_status debe ser uno de los siguientes valores: ' . implode(', ', OrderStatus::getValues()),
            'time_period.in' => 'El campo time_period debe ser uno de los siguientes valores: this_week, this_month, last_3_months, last_6_months, this_year.',
        ];
    }
}