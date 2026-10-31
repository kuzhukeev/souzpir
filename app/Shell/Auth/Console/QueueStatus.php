<?php

namespace App\Shell\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Aggregate-only operator view; never loads queue payloads or starts workers. */
final class QueueStatus extends Command
{
    protected $signature = 'runtime:queue-status';

    protected $description = 'Show aggregate database queue counts for this instance.';

    public function handle(): int
    {
        try {
            $queueDb = config('queue.connections.database.connection') ?: config('database.default');
            $failedDb = config('queue.failed.database') ?: config('database.default');
            $jobsTable = config('queue.connections.database.table');
            $failedTable = config('queue.failed.table');
            $namespace = config('runtime.queue_namespace');
            $configured = config('runtime.queues');
            $keys = ['seller-turns', 'vision', 'ai', 'notifications', 'default'];
            if (config('queue.default') !== 'database'
                || ! in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)
                || ! is_string($queueDb) || $queueDb === '' || $queueDb !== $failedDb
                || ! $this->tableName($jobsTable) || ! $this->tableName($failedTable)
                || ! is_string($namespace) || preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $namespace) !== 1
                || ! is_array($configured) || count($configured) !== count($keys)) {
                throw new \LogicException;
            }
            $queues = [];
            foreach ($keys as $key) {
                if (($configured[$key] ?? null) !== $namespace.':'.$key) {
                    throw new \LogicException;
                }
                $queues[] = $configured[$key];
            }
            $db = DB::connection($queueDb);
            if ($db->getDriverName() !== 'pgsql'
                || ! $db->getSchemaBuilder()->hasTable($jobsTable)
                || ! $db->getSchemaBuilder()->hasTable($failedTable)) {
                throw new \LogicException;
            }

            $jobs = $db->table($jobsTable)->whereIn('queue', $queues)->select('queue')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL THEN 1 ELSE 0 END) AS waiting')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved')
                ->selectRaw('0 AS failed')->groupBy('queue');
            $failed = $db->table($failedTable)->where('connection', 'database')->whereIn('queue', $queues)->select('queue')
                ->selectRaw('0 AS waiting, 0 AS reserved, COUNT(*) AS failed')->groupBy('queue');
            $counts = $db->query()->fromSub($jobs->unionAll($failed), 'queue_counts')->select('queue')
                ->selectRaw('SUM(waiting) AS waiting, SUM(reserved) AS reserved, SUM(failed) AS failed')
                ->groupBy('queue')->get()->keyBy('queue');

            $rows = [];
            foreach ($queues as $queue) {
                $count = $counts->get($queue);
                $rows[] = [$queue, (int) ($count->waiting ?? 0), (int) ($count->reserved ?? 0), (int) ($count->failed ?? 0)];
            }
            $this->table(['Очередь', 'Ожидают', 'Зарезервированы', 'Неуспешные'], $rows);

            return self::SUCCESS;
        } catch (Throwable) {
            // No exception details, SQL, paths or queue payloads reach the operator.
            $this->error('Состояние очереди недоступно: проверьте настройки и таблицы.');

            return self::FAILURE;
        }
    }

    private function tableName(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/D', $value) === 1;
    }
}
