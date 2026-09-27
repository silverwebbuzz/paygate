<?php

namespace App\Http\Shared\Credentials;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generating, rotating or revoking API credentials: the person confirms with
 * their own password (a leaked session alone can't steal or kill keys).
 * Revoking also needs a reason for the audit log.
 */
class ApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can($this->isMethod('DELETE') ? 'api_keys.delete' : 'api_keys.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
            'reason' => $this->isMethod('DELETE') ? ['required', 'string', 'max:500'] : ['prohibited'],
        ];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }
}
