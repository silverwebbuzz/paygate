<?php

namespace App\Console\Commands;

use App\Console\TestCashierPartners;
use App\Domain\Partner\Models\Partner;
use App\Domain\PartnerApi\Exceptions\ApiException;
use App\Domain\Payout\Actions\CreatePayout;
use Illuminate\Console\Command;
use Throwable;

class TestPayoutsCommand extends Command
{
    protected $signature = 'paygate:test-payouts
        {--amount=100 : Rupees each user withdraws}
        {--limit=10 : Users per partner, 1 to 10}';

    protected $description = 'Ask for a small withdrawal for every test partner user, to a bank account or a UPI id';

    public function handle(CreatePayout $create): int
    {
        $paise = $this->paise();
        $limit = $this->limit();

        if ($paise === null || $limit === null) {
            return self::FAILURE;
        }

        if (($note = TestCashierPartners::loosenRedis()) !== null) {
            $this->warn($note);
        }

        $codes = array_column(TestCashierPartners::all(), 'code');
        $partners = Partner::query()->with('activeApiKey')->whereIn('code', $codes)->get()->keyBy('code');

        foreach ($codes as $code) {
            if (! $partners->has($code)) {
                $this->error("Partner {$code} is missing. On this server run: php artisan db:seed --class=TestPartnerSeeder");

                return self::FAILURE;
            }
        }

        $stamp = now()->format('YmdHis');
        $rows = [];
        $ok = 0;
        $failed = 0;

        foreach (TestCashierPartners::all() as $group) {
            /** @var Partner $partner */
            $partner = $partners[$group['code']];
            $keyId = $partner->activeApiKey?->id;

            foreach (array_slice($group['users'], 0, $limit) as $index => $user) {
                $bank = $index % 2 === 0;
                $orderId = $group['code'].'-OUT-'.$user['id'].'-'.$stamp;
                $beneficiary = $bank
                    ? [
                        'type' => 'bank',
                        'name' => $user['name'],
                        'account_number' => TestCashierPartners::bankAccount($user['id']),
                        'ifsc' => 'HDFC0001234',
                        'bank_name' => 'HDFC Bank',
                    ]
                    : [
                        'type' => 'upi',
                        'name' => $user['name'],
                        'upi_id' => $user['id'].'@okhdfcbank',
                    ];

                try {
                    $created = $create->handle($partner, [
                        'order_id' => $orderId,
                        'amount' => $paise,
                        'customer' => $user,
                        'beneficiary' => $beneficiary,
                        'metadata' => ['source' => 'paygate:test-payouts'],
                    ], $keyId);

                    $payout = $created['payout'];
                    $rows[] = [
                        $group['code'],
                        $user['name'],
                        $bank ? 'Bank' : 'UPI',
                        '₹'.number_format($paise / 100),
                        $payout->reference,
                        $bank ? $beneficiary['account_number'] : $beneficiary['upi_id'],
                        $payout->status,
                    ];
                    $ok++;
                } catch (ApiException $exception) {
                    $rows[] = [$group['code'], $user['name'], $bank ? 'Bank' : 'UPI', '₹'.number_format($paise / 100), '', $bank ? $beneficiary['account_number'] : $beneficiary['upi_id'], $exception->getMessage()];
                    $failed++;
                } catch (Throwable $exception) {
                    $rows[] = [$group['code'], $user['name'], $bank ? 'Bank' : 'UPI', '₹'.number_format($paise / 100), '', '', $exception->getMessage()];
                    $failed++;
                }
            }
        }

        $this->table(['Partner', 'User', 'To', 'Amount', 'Reference', 'Destination', 'Status'], $rows);
        $this->newLine();
        $this->info("{$ok} withdrawals are in a branch queue. {$failed} were refused.");
        $this->line('A withdrawal needs an approved deposit first. ₹200 in, after 5% commission, leaves ₹190. ₹100 out plus the 4% fee needs ₹104 of that.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function paise(): ?int
    {
        $rupees = (string) $this->option('amount');

        if (preg_match('/^\d+$/', $rupees) !== 1) {
            $this->error('Amount must be a whole number of rupees, such as 100.');

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
}
