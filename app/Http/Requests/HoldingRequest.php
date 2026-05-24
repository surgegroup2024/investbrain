<?php

declare(strict_types=1);

namespace App\Http\Requests;

class HoldingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {

        $rules = [
            'reinvest_dividends' => ['sometimes', 'boolean'],
            'quantity_override' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'avg_cost_override' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];

        return $rules;
    }
}
