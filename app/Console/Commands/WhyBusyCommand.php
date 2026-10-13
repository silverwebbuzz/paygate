<?php

namespace App\Console\Commands;

use App\Domain\Allocation\AccountAvailability;
use App\Domain\Allocation\Actions\AllocateAccount;
use App\Domain\Partner\Models\Partner;
use App\Domain\Transaction\Models\Transaction;
use Illuminate\Console\Command;

class WhyBusyCommand extends Command
{
    protected $signature = 'paygate:why-busy
        {reference? : Pay-in reference, e.g. PI2610134ABCDEFG}
        {--partner= : Partner code, when there is no reference}
        {--amount= : Rupees, when there is no reference}';

    protected $description = 'Show which accounts can take a pay-in right now, and why the others are blocked';

    public function handle(AccountAvailability $availability, AllocateAccount $allocate): int
    {
        $target = $this->target();

        if ($target === null) {
            return self::FAILURE;
        }

        [$partner, $amount] = $target;

        $this->info("Partner {$partner->code} ({$partner->name}), amount ₹".number_format($amount / 100, 2));

        $offered = array_values(array_filter(AllocateAccount::METHODS, fn (string $method) => $allocate->partnerAllows($partner, $method)));
        $this->line('Methods the partner offers: '.($offered === [] ? 'none' : implode(', ', $offered)));

        $accounts = $availability->accounts($partner, $amount);

        $this->table(
            ['Branch', 'Account', 'Methods', 'Can take it?', 'Why not'],
            array_map(fn (array $row) => [
                $row['branch'],
                $row['account'],
                implode(', ', $row['methods']),
                $row['blockers'] === [] ? 'yes' : 'no',
                implode("\n", $row['blockers']),
            ], $accounts),
        );

        $available = $availability->methods($partner, $amount);

        foreach ($offered as $method) {
            in_array($method, $available, true)
                ? $this->info("{$method}: available")
                : $this->error("{$method}: busy (no account above can take it)");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: Partner, 1: int}|null
     */
    private function target(): ?array
    {
        $reference = $this->argument('reference');

        if (is_string($reference) && $reference !== '') {
            $payin = Transaction::query()->with('partner')->where('direction', 'payin')->where('reference', strtoupper(trim($reference)))->first();

            if ($payin === null) {
                $this->error("No pay-in with reference {$reference}.");

                return null;
            }

            $this->line("Pay-in {$payin->reference} is {$payin->status}.");

            return [$payin->partner, $payin->amount];
        }

        $code = (string) $this->option('partner');
        $rupees = (string) $this->option('amount');

        if ($code === '' || preg_match('/^\d+(\.\d{1,2})?$/', $rupees) !== 1) {
            $this->error('Give a pay-in reference, or --partner=CODE and --amount=RUPEES.');

            return null;
        }

        $partner = Partner::query()->whereRaw('upper(code) = ?', [strtoupper($code)])->first();

        if ($partner === null) {
            $this->error("No partner with code {$code}.");

            return null;
        }

        return [$partner, (int) round((float) $rupees * 100)];
    }
}
