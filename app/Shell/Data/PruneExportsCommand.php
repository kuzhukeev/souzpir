<?php

namespace App\Shell\Data;

use Illuminate\Console\Command;
use Throwable;

final class PruneExportsCommand extends Command
{
    protected $signature = 'data:prune-exports {--limit=100}';

    protected $description = 'Remove a bounded batch of expired and abandoned private export files.';

    public function handle(PruneExports $cleanup): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $grace = filter_var(config('features.E1-11.defaults.export_orphan_grace_minutes', 60), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10080]]);
        if ($limit === false || $grace === false) {
            $this->error('Invalid export cleanup settings.');

            return self::FAILURE;
        }
        try {
            $count = $cleanup->prune($limit, $grace);
        } catch (Throwable $error) {
            report($error);
            $this->error('Export cleanup failed.');

            return self::FAILURE;
        }
        $this->info('Private export files removed: '.$count);

        return self::SUCCESS;
    }
}
