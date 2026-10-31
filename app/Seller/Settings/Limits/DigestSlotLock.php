<?php

namespace App\Seller\Settings\Limits;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DigestSlotLock
{
    /** All writers and closers of the same slot must take this transaction-scoped lock first. */
    public function acquire(string $organization, string $brand, string $user, string $channel, string $due): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Digest slot lock requires a transaction.');
        }
        $instant = CarbonImmutable::parse($due)->utc()->format('Y-m-d\TH:i:s.u\Z');
        $key = hash('sha256', implode('|', ['seller-digest-v1', $organization, $brand, $user, $channel, $instant]));
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
