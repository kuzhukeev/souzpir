<?php

namespace App\Shell\Integrations;

use Illuminate\Console\Command;
use Throwable;

final class PruneSecretReceiptsCommand extends Command
{
    protected $signature = 'integrations:prune-secret-receipts {--limit=500}';

    protected $description = 'Delete one bounded batch of expired integration-secret receipts';

    public function handle(PruneSecretReceipts $pruner): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PruneSecretReceipts::MAX_BATCH]]);
        if ($limit === false) {
            $this->error('Invalid receipt cleanup limit.');

            return self::FAILURE;
        }
        try {
            $count = $pruner->prune($limit);
        } catch (Throwable) {
            // Do not log exception messages or receipt hashes.
            $this->error('Receipt cleanup failed.');

            return self::FAILURE;
        }
        $this->info("Expired receipts removed: {$count}.");

        return self::SUCCESS;
    }
}
