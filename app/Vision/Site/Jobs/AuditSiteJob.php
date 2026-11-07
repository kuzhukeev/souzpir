<?php

namespace App\Vision\Site\Jobs;

use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Site\AuditRun;
use App\Vision\Site\SiteAccess;
use App\Vision\SiteAudit\SiteInspector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AuditSiteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 75;

    public int $tries = 1;

    public function __construct(public readonly string $organizationId, public readonly string $brandId, public readonly string $actorId, public readonly string $membershipId, public readonly string $url, private readonly string $sessionHash, private readonly int $authVersion) {}

    public static function key(string $organizationId, string $brandId): string
    {
        return 'vision:site:audit:'.$organizationId.':'.$brandId;
    }

    public function handle(SiteInspector $inspector, SiteAccess $access, AuditWriter $audit): void
    {
        $key = self::key($this->organizationId, $this->brandId);
        $tenant = new TenantContext($this->organizationId, $this->brandId, $this->actorId, $this->membershipId, 'job');
        $started = microtime(true);
        Cache::put($key, [...Cache::get($key, []), 'started_at_epoch' => $started], now()->addDay());
        try {
            app(TenantContextRunner::class)->run($tenant, function () use ($inspector, $access, $audit, $tenant, $key, $started): void {
                DB::transaction(function () use ($access, $tenant): void {
                    $this->assertSessionActive();
                    $access->lock($tenant, true, 'vision.actions.write');
                });
                $this->progress($key, 'inspecting');
                $result = $inspector->inspect($this->url, config('features.E8-01B.defaults.crawl_pages'), function (int $processed, int $total) use ($key, $started): void {
                    $data = Cache::get($key, []);
                    $data['pages_processed'] = $processed;
                    $data['pages_total'] = $total;
                    $data['remaining_seconds'] = $processed > 0
                        ? (int) ceil(((microtime(true) - $started) / $processed) * max(0, $total - $processed))
                        : null;
                    Cache::put($key, $data, now()->addDay());
                    if ($processed >= $total) {
                        $this->progress($key, 'analyzing');
                    }
                });
                $this->progress($key, 'saving');
                DB::transaction(function () use ($access, $audit, $tenant, $result): void {
                    $this->assertSessionActive();
                    $access->lock($tenant, true, 'vision.actions.write');
                    $run = new AuditRun;
                    $run->result = $result;
                    $run->created_by = $tenant->actorId;
                    $run->save();
                    $audit->record($tenant, new AuditEntry('vision.site.audit.created', 'site_audit', $run->id));
                });
                $this->progress($key, 'complete');
            });
        } catch (Throwable) {
            $this->progress($key, 'failed');
        } finally {
            $data = Cache::get($key, []);
            Cache::put($key, [...$data, 'elapsed_seconds' => (int) ceil(microtime(true) - $started)], now()->addDay());
        }
    }

    private function progress(string $key, string $stage): void
    {
        $data = Cache::get($key, []);
        $events = $data['events'] ?? [];
        $events[] = ['stage' => $stage, 'at' => now()->toIso8601String()];
        Cache::put($key, [...$data, 'stage' => $stage, 'events' => $events], now()->addDay());
    }

    /** The job stores only the session digest, and checks the same lease before the protected write. */
    private function assertSessionActive(): void
    {
        $row = DB::table('auth_session_registry')->where('session_hash', $this->sessionHash)->lockForUpdate()->first();
        $user = User::whereKey($this->actorId)->lock('for no key update')->first();
        $remember = $user?->getRememberToken();
        if (! $row || ! $user || $row->user_id !== $user->id || $row->revoked_at !== null
            || now()->greaterThanOrEqualTo($row->expires_at) || $this->authVersion !== $user->auth_version
            || (int) $row->auth_version !== $user->auth_version
            || ($row->remember_fingerprint !== null && (! is_string($remember) || ! hash_equals($row->remember_fingerprint, hash_hmac('sha256', 'auth-remember:'.$remember, (string) config('app.key')))))) {
            throw new AuthenticationException('Unauthenticated.');
        }
    }
}
