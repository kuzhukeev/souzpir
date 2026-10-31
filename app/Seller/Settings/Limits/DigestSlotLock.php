<?php

namespace App\Seller\Settings\Limits;

use Illuminate\Support\Facades\DB;
use LogicException;

final class DigestSlotLock
{
    /** All writers and closers of the same slot must take this transaction-scoped lock first. */
    public function acquire(string $organization, string $brand, string $user, string $channel, string $date): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Digest slot lock requires a transaction.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new LogicException('Invalid digest slot date.');
        }
        $key = hash('sha256', implode('|', ['seller-digest-v2', $organization, $brand, $user, $channel, $date]));
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
