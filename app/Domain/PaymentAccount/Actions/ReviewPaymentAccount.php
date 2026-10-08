<?php

namespace App\Domain\PaymentAccount\Actions;

use App\Domain\Core\Identity\Models\User;
use App\Domain\PaymentAccount\Enums\AccountVerification;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use Illuminate\Validation\ValidationException;

class ReviewPaymentAccount
{
    public function __construct(private SetAccountVerification $verification) {}

    public function approve(User $actor, PaymentAccount $account): PaymentAccount
    {
        $this->ensurePending($account);

        return $this->verification->handle($actor, $account, AccountVerification::Verified);
    }

    public function reject(User $actor, PaymentAccount $account, string $reason): PaymentAccount
    {
        $this->ensurePending($account);

        return $this->verification->handle($actor, $account, AccountVerification::Unverified, $reason);
    }

    private function ensurePending(PaymentAccount $account): void
    {
        if ($account->verification !== AccountVerification::Pending) {
            throw ValidationException::withMessages(['verification' => __('Only accounts waiting for verification can be approved or rejected.')]);
        }
    }
}
