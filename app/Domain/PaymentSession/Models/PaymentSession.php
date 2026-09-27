<?php

namespace App\Domain\PaymentSession\Models;

use App\Domain\Transaction\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The customer's payment page for a pay-in. The URL carries a token derived
 * from the session id with an HMAC under APP_KEY: unguessable, reproducible
 * (a repeated create returns the same link) and only its SHA-256 is stored,
 * so a database leak alone doesn't expose live links.
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $token_hash
 * @property string $status issued / opened / completed / expired
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $method_selected_at
 * @property string|null $client_ip
 * @property string|null $user_agent
 * @property CarbonInterface $created_at
 * @property-read Transaction $transaction
 */
class PaymentSession extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'method_selected_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public static function tokenFor(string $sessionId): string
    {
        $key = (string) config('app.key');

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'payment-session:'.$sessionId, $key, true)), '+/', '-_'), '=');
    }

    public function token(): string
    {
        return self::tokenFor($this->id);
    }

    public function url(): string
    {
        return route('pay.checkout.show', ['token' => $this->token()]);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
