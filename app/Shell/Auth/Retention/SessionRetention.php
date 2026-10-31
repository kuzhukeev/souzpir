<?php

namespace App\Shell\Auth\Retention;

use Illuminate\Support\Facades\DB;
use LogicException;

final class SessionRetention
{
    /** Delete one bounded batch; rows currently locked by login/logout are skipped. */
    public function prune(int $days, int $limit): int
    {
        if ($days < 30 || $days > 3650 || $limit < 1 || $limit > 500 || DB::transactionLevel() !== 0) {
            throw new LogicException('Invalid session retention settings.');
        }
        $cutoff = now()->subDays($days);

        return DB::transaction(function () use ($cutoff, $limit): int {
            $ids = DB::table('auth_session_registry')
                ->where(function ($q) use ($cutoff): void {
                    $q->where(fn ($q) => $q->whereNotNull('revoked_at')->where('revoked_at', '<=', $cutoff))
                        ->orWhere(fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '<=', $cutoff));
                })
                ->orderBy('expires_at')->orderBy('session_hash')->limit($limit)
                ->lock('for update skip locked')->pluck('session_hash');

            return $ids->isEmpty() ? 0 : DB::table('auth_session_registry')->whereIn('session_hash', $ids)->delete();
        });
    }
}
