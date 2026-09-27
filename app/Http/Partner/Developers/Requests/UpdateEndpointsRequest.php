<?php

namespace App\Http\Partner\Developers\Requests;

use App\Domain\Core\Identity\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEndpointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('webhooks.update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $url = ['nullable', 'string', 'max:255', app()->isLocal() ? 'url:http,https' : 'url:https'];

        return [
            'return_url' => $url,
            'callback_url' => $url,
            'payin_webhook_url' => $url,
            'payout_webhook_url' => $url,
        ];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }
}
