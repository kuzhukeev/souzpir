<?php

namespace App\Shell\Auth\Retention;

use Illuminate\Console\Command;
use Throwable;

final class PruneSessions extends Command
{
    protected $signature = 'auth:prune-session-registry {--limit=100}';

    protected $description = 'Delete one bounded batch of expired or revoked session registry records.';

    public function handle(SessionRetention $retention): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $days = filter_var(config('features.E1-01C.defaults.retention_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 3650]]);
        if ($limit === false || $days === false) {
            $this->error('Invalid session retention settings.');

            return self::FAILURE;
        }
        try {
            $count = $retention->prune($days, $limit);
        } catch (Throwable) {
            $this->error('Session retention failed.');

            return self::FAILURE;
        }
        $this->info('Expired or revoked sessions removed: '.$count);

        return self::SUCCESS;
    }
}
