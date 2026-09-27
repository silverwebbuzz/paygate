<?php

namespace App\Http\Api\V1\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/payins. `amount` is in paise (integer): 12534.00 rupees = 1253400.
 */
class CreatePayinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authenticated by AuthenticatePartner
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'amount' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'customer' => ['required', 'array'],
            'customer.id' => ['required', 'string', 'max:100'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.mobile' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'customer.username' => ['nullable', 'string', 'max:150'],
            'return_url' => ['nullable', 'string', 'max:255', app()->isLocal() ? 'url:http,https' : 'url:https'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.integer' => 'amount must be an integer number of paise (₹125.50 = 12550).',
            'amount.min' => 'amount must be at least 100 paise (₹1).',
        ];
    }
}
