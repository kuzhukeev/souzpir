<?php

namespace App\Vision\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Serialize Core gate→measurement lock upgrades without holding locks during AI calls. */
final class MeasurementLock
{
    public static function acquire(string $measurementId): void
    {
        if (DB::transactionLevel() < 1 || ! Str::isUlid($measurementId)) {
            throw new LogicException('Measurement lock requires a transaction and valid identifier.');
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['vision-core:'.$measurementId]);
    }
}
