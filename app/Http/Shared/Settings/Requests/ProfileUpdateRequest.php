<?php

namespace App\Http\Shared\Settings\Requests;

use App\Domain\Core\Identity\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Users may change their name only. Email is the login identity, so it is
        // changed by an administrator (audited), never self-service.
        return ['name' => $this->nameRules()];
    }
}
