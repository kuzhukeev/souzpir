<?php

namespace App\Vision\Site\Jobs;

use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Site\AuditRun;
use App\Vision\Site\SiteAccess;
use App\Vision\SiteAudit\SiteInspector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AuditSiteJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $organizationId, public readonly string $brandId, public readonly string $actorId, public readonly string $membershipId, public readonly string $url) {}

    public static function key(string $organizationId, string $brandId): string
    {
        return 'vision:site:audit:'.$organizationId.':'.$brandId;
    }

    public function handle(SiteInspector $inspector, SiteAccess $access, AuditWriter $audit): void
    {
        $key = self::key($this->organizationId, $this->brandId);
        $tenant = new TenantContext($this->organizationId, $this->brandId, $this->actorId, $this->membershipId, 'job');
        try {
            app(TenantContextRunner::class)->run($tenant, function () use ($inspector, $access, $audit, $tenant, $key): void {
                DB::transaction(fn () => $access->lock($tenant, true, 'vision.actions.write'));
                Cache::put($key, ['stage' => 'inspecting'], now()->addDay());
                $result = $inspector->inspect($this->url);
                Cache::put($key, ['stage' => 'saving'], now()->addDay());
                DB::transaction(function () use ($access, $audit, $tenant, $result): void {
                    $access->lock($tenant, true, 'vision.actions.write');
                    $run = new AuditRun;
                    $run->result = $result;
                    $run->created_by = $tenant->actorId;
                    $run->save();
                    $audit->record($tenant, new AuditEntry('vision.site.audit.created', 'site_audit', $run->id));
                });
                Cache::put($key, ['stage' => 'complete'], now()->addDay());
            });
        } catch (Throwable) {
            Cache::put($key, ['stage' => 'failed'], now()->addDay());
        }
    }
}
