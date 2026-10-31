<?php

namespace App\Shell\Integrations;

use Illuminate\Support\Facades\DB;
use LogicException;

/** One bounded batch; live or concurrently locked receipts are never deleted. */
final class PruneSecretReceipts
{
    public const MAX_BATCH = 1000;

    public function prune(int $limit): int
    {
        if ($limit < 1 || $limit > self::MAX_BATCH || DB::transactionLevel() !== 0 || DB::connection()->getDriverName() !== 'pgsql') {
            throw new LogicException('Invalid receipt cleanup settings.');
        }
        $cutoff = now();

        return DB::transaction(function () use ($cutoff, $limit): int {
            $keys = DB::table('integration_secret_receipts')->where('expires_at', '<=', $cutoff)
                ->orderBy('expires_at')->orderBy('nonce_hash')->limit($limit)
                ->lock('for update skip locked')->pluck('nonce_hash');

            return $keys->isEmpty() ? 0 : DB::table('integration_secret_receipts')
                ->whereIn('nonce_hash', $keys)->where('expires_at', '<=', $cutoff)->delete();
        });
    }
}
