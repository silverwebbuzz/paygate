<?php

namespace App\Console\Commands;

use App\Domain\Partner\Models\PartnerApiKey;
use App\Support\ApiEnvironment;
use Illuminate\Console\Command;

/**
 * Before turning on (or deploying) the key check of ApiAuthenticator: lists
 * the usable API keys this server would refuse as belonging to the other
 * server. Those partners need a new key here (Rotate) first.
 */
class KeyEnvironmentsCommand extends Command
{
    protected $signature = 'paygate:key-environments';

    protected $description = 'Show this server\'s environment (live / sandbox) and the API keys it would refuse as the other server\'s';

    public function handle(): int
    {
        $this->info('This server: '.ApiEnvironment::label().'. Keys issued and accepted here: pk_'.ApiEnvironment::keyPrefix().'_…');
        $this->line('Key check (wrong_environment): '.(config('paygate.api.enforce_key_environment') ? 'on' : 'off'));

        $refused = PartnerApiKey::query()
            ->with('partner')
            ->whereIn('status', ['active', 'rotating'])
            ->get()
            ->filter(fn (PartnerApiKey $key) => ApiEnvironment::refuses($key->key_id));

        if ($refused->isEmpty()) {
            $this->info('No usable key is another server\'s.');

            return self::SUCCESS;
        }

        $this->warn("{$refused->count()} usable key(s) are another server's and will be refused. Rotate them here (new key), or check PAYGATE_ENVIRONMENT:");
        $this->table(['Partner', 'Key id', 'Status', 'Last used'], $refused->map(fn (PartnerApiKey $key) => [
            $key->partner?->code.' '.$key->partner?->name,
            $key->key_id,
            $key->status,
            $key->last_used_at?->toDateTimeString() ?? 'never',
        ])->all());

        return self::SUCCESS;
    }
}
