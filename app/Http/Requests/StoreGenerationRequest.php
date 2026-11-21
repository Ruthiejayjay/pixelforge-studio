<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount_usd' => [
                'required',
                'regex:/^\d+(?:\.\d{1,2})?$/',
                'numeric',
                'min:0.50',
                'max:500',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_usd.regex' => 'Enter a valid US dollar amount with up to 2 decimal places (e.g. 5.00).',
            'amount_usd.min' => 'Minimum top-up amount is $0.50.',
            'amount_usd.max' => 'Maximum top-up amount per transaction is $500.00.',
        ];
    }
}
