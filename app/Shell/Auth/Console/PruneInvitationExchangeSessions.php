<?php

namespace App\Shell\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneInvitationExchangeSessions extends Command
{
    protected $signature = 'auth:prune-invitation-exchanges';

    protected $description = 'Remove expired invitation exchange session records';

    public function handle(): int
    {
        for ($batch = 0; $batch < 10; $batch++) {
            $count = DB::transaction(function (): int {
                $keys = DB::table('invitation_exchange_sessions')
                    ->where('updated_at', '<', now()->subMinutes(max(1, (int) config('session.lifetime', 120))))
                    ->where(fn ($query) => $query->whereNull('grant_expires_at')->orWhere('grant_expires_at', '<=', now()))
                    ->orderBy('updated_at')->orderBy('session_hash')->limit(1000)->lock('for update skip locked')
                    ->pluck('session_hash');

                return $keys->isEmpty() ? 0 : DB::table('invitation_exchange_sessions')->whereIn('session_hash', $keys)->delete();
            });
            if ($count < 1000) {
                break;
            }
        }

        return self::SUCCESS;
    }
}
