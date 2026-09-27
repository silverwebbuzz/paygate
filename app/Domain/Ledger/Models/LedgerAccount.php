<?php

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A ledger account. Position accounts belong to one partner↔branch pair
 * (partner_position, branch_position); platform accounts to nobody.
 * Sign: positive = the platform owes the party (Database.md §3.1).
 *
 * @property string $id
 * @property string $kind
 * @property string|null $partner_id
 * @property string|null $branch_id
 */
class LedgerAccount extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];
}
