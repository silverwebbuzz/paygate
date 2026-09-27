<?php

namespace Tests\Concerns;

use App\Domain\Branch\Models\Branch;
use App\Domain\Commission\Models\CommissionRate;
use App\Domain\Core\Identity\Models\User;
use App\Domain\Network\Models\PartnerBranchMapping;
use App\Domain\Partner\Actions\IssueApiKey;
use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerIpRule;
use App\Domain\PaymentAccount\Actions\ChangeAccountStatus;
use App\Domain\PaymentAccount\Actions\ReviewPaymentAccount;
use App\Domain\PaymentAccount\Actions\SavePaymentAccount;
use App\Domain\PaymentAccount\Enums\AccountStatus;
use App\Domain\PaymentAccount\Models\PaymentAccount;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Testing\TestResponse;

/**
 * A ready-to-trade network for pay-in tests (partner with key and allowed
 * IP, mapped active branch, active accounts) and signed Partner API calls.
 */
trait BuildsPayinNetwork
{
    protected Partner $partner;

    protected string $keyId;

    protected string $secret;

    protected Branch $branch;

    protected User $networkAdmin;

    private int $accountSeq = 0;

    protected function buildNetwork(): void
    {
        $this->networkAdmin = User::factory()->admin()->create();
        $this->partner = Partner::factory()->create([
            'website_url' => 'https://atoz.example',
            'return_url' => 'https://atoz.example/return',
            'allow_upi' => true,
            'allow_qr' => true,
            'allow_bank_transfer' => true,
            'deposit_min_amount' => 10000,
            'deposit_max_amount' => 10000000,
        ]);

        $issued = app(IssueApiKey::class)->handle($this->networkAdmin, $this->partner);
        $this->keyId = $issued['key']->key_id;
        $this->secret = $issued['secret'];

        PartnerIpRule::create(['partner_id' => $this->partner->id, 'cidr' => '127.0.0.1/32']);

        $this->branch = Branch::factory()->create();
        $this->mapPair($this->partner, $this->branch);
    }

    protected function mapPair(Partner $partner, Branch $branch): PartnerBranchMapping
    {
        return PartnerBranchMapping::create(['partner_id' => $partner->id, 'branch_id' => $branch->id, 'status' => 'active']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function activeAccount(?Branch $branch = null, array $overrides = []): PaymentAccount
    {
        $this->accountSeq++;
        $branch ??= $this->branch;

        $account = app(SavePaymentAccount::class)->handle($this->networkAdmin, $branch, null, [
            'label' => "Account {$this->accountSeq}",
            'account_holder_name' => "Holder {$this->accountSeq}",
            'is_bank_enabled' => true,
            'bank_name' => 'HDFC Bank',
            'ifsc' => 'HDFC0001203',
            'account_number' => '5010048271'.str_pad((string) $this->accountSeq, 4, '0', STR_PAD_LEFT),
            'is_upi_enabled' => true,
            'upi_id' => "holder{$this->accountSeq}@hdfcbank",
            'is_qr_enabled' => true,
            'is_intent_enabled' => true,
            'max_open_sessions' => 5,
            ...$overrides,
        ]);

        app(ReviewPaymentAccount::class)->approve($this->networkAdmin, $account);
        app(ChangeAccountStatus::class)->handle($this->networkAdmin, $account, AccountStatus::Active);

        return $account->refresh();
    }

    /**
     * A signed Partner API request, as the partner's server would send it.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $path, ?array $body = null, array $headers = [], ?string $secret = null, ?string $keyId = null): TestResponse
    {
        $json = $body === null ? '' : (string) json_encode($body);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $canonical = implode("\n", [$timestamp, $nonce, strtoupper($method), $path, hash('sha256', $json)]);

        $server = [
            'HTTP_X_KEY_ID' => $keyId ?? $this->keyId,
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_NONCE' => $nonce,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $canonical, $secret ?? $this->secret),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, 'http://api.paygate.local'.$path, [], [], [], $server, $json);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payinBody(array $overrides = []): array
    {
        return [
            'order_id' => 'ORD-'.bin2hex(random_bytes(4)),
            'amount' => 1253400,
            'customer' => ['id' => 'cust-1', 'name' => 'Ravi Kumar', 'mobile' => '9876543210'],
            ...$overrides,
        ];
    }

    /**
     * Creates a pay-in through the API and returns [reference, payment token].
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: string, 1: string}
     */
    protected function createPayin(array $overrides = []): array
    {
        $data = $this->api('POST', '/v1/payins', $this->payinBody($overrides))->assertCreated()->json('data');

        return [$data['id'], basename((string) parse_url($data['payment_url'], PHP_URL_PATH))];
    }

    /**
     * Sets a rate in force since yesterday (type partner / branch / mapping).
     */
    protected function rate(string $type, string $id, string $direction, string $rate, ?string $side = null): void
    {
        CommissionRate::create([
            'subject_type' => $type, 'subject_id' => $id, 'side' => $side ?? $type, 'direction' => $direction,
            'rate_percent' => $rate, 'effective_from' => now()->subDay(),
        ]);
    }

    /**
     * A pay-in the customer says they paid (UTR submitted), allocated to an
     * account of $this->branch. Returns the transaction.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function submittedPayin(array $overrides = [], string $utr = '626812820491'): Transaction
    {
        [$reference, $token] = $this->createPayin($overrides);
        $this->post("http://pay.paygate.local/p/{$token}/method", ['method' => 'upi'])->assertSessionHasNoErrors();
        $this->post("http://pay.paygate.local/p/{$token}/proof", ['utr' => $utr])->assertSessionHasNoErrors();

        return Transaction::where('reference', $reference)->firstOrFail();
    }
}
