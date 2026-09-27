<?php

namespace App\Http\Partner\Developers\Requests;

use App\Domain\Core\Identity\Models\User;
use App\Http\Shared\Rules\IpAddressList;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIpRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->actor()->can('ip_rules.create') && $this->actor()->can('ip_rules.delete');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['ip_addresses' => ['nullable', 'string', new IpAddressList]];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }
}
