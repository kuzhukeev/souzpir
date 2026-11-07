<?php

namespace App\Vision\Competitors\Jobs;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Competitors\RecalculateMergedReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RecalculateMergedReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $organizationId, public string $brandId) {}

    public static function key(string $organizationId, string $brandId): string
    {
        return 'vision:competitors:merge:'.$organizationId.':'.$brandId;
    }

    public function handle(TenantContextRunner $runner): void
    {
        $key = self::key($this->organizationId, $this->brandId);
        Cache::put($key, ['stage' => 'recalculating', 'started_at' => now()->toIso8601String()], now()->addHour());
        $tenant = new TenantContext($this->organizationId, $this->brandId, source: 'job');
        try {
            $runner->run($tenant, fn () => app(RecalculateMergedReport::class)->create($tenant));
            Cache::put($key, ['stage' => 'complete'], now()->addMinutes(5));
        } catch (Throwable) {
            Cache::put($key, ['stage' => 'failed'], now()->addMinutes(5));
        }
    }
}
