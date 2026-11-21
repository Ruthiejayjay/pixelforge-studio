<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount_usd.regex' => 'Enter a valid US dollar amount with up to 2 decimal places (e.g. 5.00).',
            'amount_usd.min' => 'Minimum top-up amount is $0.50.',
            'amount_usd.max' => 'Maximum top-up amount per transaction is $500.00.',
        ];
    }
}
