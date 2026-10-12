<?php

namespace App\Console\Commands;

use App\Console\TestCashierPartners;
use App\Domain\Allocation\Actions\AllocateAccount;
use App\Domain\Allocation\Exceptions\NoAccountAvailable;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\Exceptions\ApiException;
use App\Domain\Transaction\Actions\CreatePayin;
use App\Domain\Transaction\Actions\SubmitPayinProof;
use App\Domain\Transaction\Enums\PayinStatus;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class TestPayinsCommand extends Command
{
    protected $signature = 'paygate:test-payins
        {--amount=200 : Rupees each user pays}
        {--limit=10 : Users per partner, 1 to 10}';

    protected $description = 'Create a small pay-in for every test partner user and assign a branch bank or UPI account';

    public function handle(CreatePayin $create, AllocateAccount $allocate, SubmitPayinProof $proof): int
    {
        $paise = $this->paise();
        $limit = $this->limit();

        if ($paise === null || $limit === null) {
            return self::FAILURE;
        }

        if (($note = TestCashierPartners::loosenRedis()) !== null) {
            $this->warn($note);
        }

        $partners = $this->partners();

        if ($partners === null) {
            return self::FAILURE;
        }

        $stamp = now()->format('YmdHis');
        $rows = [];
        $ok = 0;
        $failed = 0;

        foreach (TestCashierPartners::all() as $group) {
            $partner = $partners[$group['code']];
            $keyId = $partner->activeApiKey?->id;

            foreach (array_slice($group['users'], 0, $limit) as $index => $user) {
                $method = $index % 2 === 0 ? 'bank_transfer' : 'upi';
                $orderId = $group['code'].'-PAY-'.$user['id'].'-'.$stamp;

                try {
                    $payin = Transaction::query()
                        ->where('partner_id', $partner->id)
                        ->where('direction', 'payin')
                        ->whereIn('status', [PayinStatus::Created->value, PayinStatus::AwaitingPayment->value])
                        ->where('metadata->source', 'paygate:test-payins')
                        ->whereHas('customer', fn ($query) => $query->where('external_id', $user['id']))
                        ->latest()
                        ->first();

                    if ($payin === null) {
                        $payin = $create->handle($partner, [
                            'order_id' => $orderId,
                            'amount' => $paise,
                            'customer' => $user,
                            'return_url' => $partner->return_url,
                            'metadata' => ['source' => 'paygate:test-payins', 'method' => $method],
                        ], $keyId)['payin'];
                    } elseif (is_string($payin->method) && $payin->method !== '') {
                        $method = $payin->method;
                    }

                    if ($payin->payinStatus() === PayinStatus::Created) {
                        $allocate->handle($payin, $method);
                        $payin->refresh();
                    }

                    $payin = $proof->handle($payin, strtoupper(substr(hash('sha256', $payin->partner_transaction_id), 0, 12)), null);
                    $payin->load('paymentAccount');

                    $account = $payin->paymentAccount;
                    $where = $method === 'upi'
                        ? (string) $account?->upi_id_encrypted
                        : trim(((string) $account?->bank_name).' '.((string) $account?->account_number_encrypted));

                    $rows[] = [$group['code'], $user['name'], $method === 'upi' ? 'UPI' : 'Bank', '₹'.number_format($paise / 100), $payin->reference, $account?->label ?? '', $where, $payin->status];
                    $ok++;
                } catch (NoAccountAvailable $exception) {
                    $rows[] = [$group['code'], $user['name'], $method, '₹'.number_format($paise / 100), '', '', $exception->getMessage(), 'failed'];
                    $failed++;
                } catch (ApiException $exception) {
                    $rows[] = [$group['code'], $user['name'], $method, '₹'.number_format($paise / 100), '', '', $exception->getMessage(), $exception->errorCode];
                    $failed++;
                } catch (ValidationException $exception) {
                    $rows[] = [$group['code'], $user['name'], $method, '₹'.number_format($paise / 100), '', '', collect($exception->errors())->flatten()->first(), 'invalid'];
                    $failed++;
                } catch (Throwable $exception) {
                    $rows[] = [$group['code'], $user['name'], $method, '₹'.number_format($paise / 100), '', '', $exception->getMessage(), 'error'];
                    $failed++;
                }
            }
        }

        $this->table(['Partner', 'User', 'Method', 'Amount', 'Reference', 'Account', 'Paid to', 'Status'], $rows);
        $this->newLine();
        $this->info("{$ok} payments are waiting for a branch to approve. {$failed} failed.");
        $this->line('Log in as branch1 … branch5 and approve the deposit queue. A ₹'.$this->option('amount').' payment credits 95% after the 5% partner fee. Then run paygate:test-payouts.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function paise(): ?int
    {
        $rupees = (string) $this->option('amount');

        if (preg_match('/^\d+$/', $rupees) !== 1) {
            $this->error('Amount must be a whole number of rupees, such as 200.');

            return null;
        }

        $value = (int) $rupees;

        if ($value < 100 || $value > 100000) {
            $this->error('Amount must be between ₹100 and ₹1,00,000. The test partners reject anything under ₹100.');

            return null;
        }

        return $value * 100;
    }

    private function limit(): ?int
    {
        $limit = (string) $this->option('limit');

        if (preg_match('/^\d+$/', $limit) !== 1 || (int) $limit < 1 || (int) $limit > 10) {
            $this->error('Limit must be from 1 to 10 users per partner.');

            return null;
        }

        return (int) $limit;
    }

    /**
     * @return array<string, Partner>|null
     */
    private function partners(): ?array
    {
        $codes = array_column(TestCashierPartners::all(), 'code');
        $partners = Partner::query()->with('activeApiKey')->whereIn('code', $codes)->get()->keyBy('code');

        foreach ($codes as $code) {
            if (! $partners->has($code)) {
                $this->error("Partner {$code} is missing. On this server run: php artisan db:seed --class=TestPartnerSeeder");

                return null;
            }
        }

        return $partners->all();
    }
}
