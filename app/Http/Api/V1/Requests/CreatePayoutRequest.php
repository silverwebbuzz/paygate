<?php

namespace App\Http\Api\V1\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /v1/payouts. `amount` in paise; the fee is charged on top (W-A).
 */
class CreatePayoutRequest extends FormRequest
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
            'beneficiary' => ['required', 'array'],
            'beneficiary.type' => ['required', 'in:bank,upi'],
            'beneficiary.name' => ['required', 'string', 'max:255'],
            'beneficiary.account_number' => ['required_if:beneficiary.type,bank', 'nullable', 'string', 'regex:/^[0-9][0-9 -]{7,24}$/'],
            'beneficiary.ifsc' => ['required_if:beneficiary.type,bank', 'nullable', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'beneficiary.bank_name' => ['nullable', 'string', 'max:255'],
            'beneficiary.upi_id' => ['required_if:beneficiary.type,upi', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,63}$/'],
            'beneficiary.email' => ['nullable', 'email', 'max:255'],
            'beneficiary.phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
