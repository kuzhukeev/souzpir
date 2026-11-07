<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Liveness check for the load balancer: DB reachability and queue backlog, never application secrets. */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $databaseOk = $this->databaseOk();
        $queue = $databaseOk ? $this->queueStatus() : ['healthy' => false, 'lag_seconds' => null, 'failed_jobs' => null];
        $healthy = $databaseOk && $queue['healthy'];

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $databaseOk,
            'queue' => $queue,
        ], $healthy ? 200 : 503);
    }

    private function databaseOk(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function queueStatus(): array
    {
        try {
            $oldest = DB::table('jobs')->min('created_at');
            $lagSeconds = $oldest !== null ? max(0, now()->timestamp - (int) $oldest) : 0;
            $failedJobs = (int) DB::table('failed_jobs')->count();
            $healthy = $lagSeconds <= (int) config('features.L1-08.defaults.queue_lag_warn_seconds')
                && $failedJobs <= (int) config('features.L1-08.defaults.failed_jobs_warn_count');

            return ['healthy' => $healthy, 'lag_seconds' => $lagSeconds, 'failed_jobs' => $failedJobs];
        } catch (Throwable) {
            return ['healthy' => false, 'lag_seconds' => null, 'failed_jobs' => null];
        }
    }
}
