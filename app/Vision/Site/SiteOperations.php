<?php

namespace App\Vision\Site;

use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Vision\Site\Jobs\AuditSiteJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Mutations retain tenant, authorization and audit in the same transaction. */
final class SiteOperations
{
    public function __construct(private SiteAccess $access, private AuditWriter $audit) {}

    public function authorize(TenantContext $tenant, Request $request): void
    {
        app(AuthSessionRegistry::class)->withinActive($request, fn () => $this->access->lock($tenant, true, 'vision.actions.write'));
    }

    public function runAudit(TenantContext $tenant, Request $request, string $url): ?string
    {
        $this->authorize($tenant, $request);
        if (! config('features.E8-01B.defaults.fetch_enabled', false)) {
            return 'Проверка сайтов не включена в этом окружении.';
        }
        if (! $tenant->organizationId || ! $tenant->brandId || ! $tenant->actorId || ! $tenant->membershipId) {
            return 'Не удалось определить доступ к бренду.';
        }
        $version = $request->session()->get('auth.version');
        if (! is_int($version)) {
            return 'Не удалось подтвердить сессию.';
        }
        $sessionHash = hash_hmac('sha256', 'auth-session:'.$request->session()->getId(), (string) config('app.key'));
        $key = AuditSiteJob::key($tenant->organizationId, $tenant->brandId);
        Cache::put($key, ['url' => $url, 'stage' => 'queued', 'events' => [['stage' => 'queued', 'at' => now()->toIso8601String()]]], now()->addDay());
        AuditSiteJob::dispatch($tenant->organizationId, $tenant->brandId, $tenant->actorId, $tenant->membershipId, $url, $sessionHash, $version);
        if (Cache::get($key)['stage'] === 'failed') {
            return 'Не удалось безопасно проверить сайт.';
        }

        return null;
    }

    public function create(TenantContext $tenant, Request $request, array $data): string
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function () use ($tenant, $data): string {
            $this->access->lock($tenant, true, 'vision.actions.write');
            $this->responsible($tenant, $data['responsible'] ?? null);
            $action = new SiteAction;
            $action->fill($data);
            $action->status = 'open';
            $action->save();
            $this->audit->record($tenant, new AuditEntry('vision.action.created', 'vision_action', $action->id, after: ['status' => 'open']));

            return $action->id;
        });
    }

    public function update(TenantContext $tenant, Request $request, string $id, array $data): void
    {
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($tenant, $id, $data): void {
            SiteAction::query()->whereKey($id)->firstOrFail();
            $this->access->lock($tenant, true, 'vision.actions.write');
            $action = SiteAction::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $previousResponsible = $action->responsible;
            if (array_key_exists('responsible', $data)) {
                if ($data['responsible'] !== $previousResponsible && $action->status !== 'open') {
                    throw ValidationException::withMessages(['responsible' => 'Передать можно только действие в работе.']);
                }
                $this->responsible($tenant, $data['responsible']);
            }
            $before = $action->status;
            if (($data['status'] ?? $action->status) === 'awaiting_measurement') {
                $completed = array_key_exists('completed_at', $data) ? $data['completed_at'] : $action->completed_at?->format('Y-m-d');
                $comment = array_key_exists('comment', $data) ? $data['comment'] : $action->comment;
                if (! $completed || ! is_string($comment) || trim($comment) === '') {
                    throw ValidationException::withMessages(['comment' => 'Укажите дату выполнения и что сделано.']);
                }
            } elseif (($data['status'] ?? null) === 'open') {
                $data['completed_at'] = null;
                $data['evidence_url'] = null;
            } elseif (array_key_exists('completed_at', $data) || array_key_exists('evidence_url', $data)) {
                throw ValidationException::withMessages(['status' => 'Сначала отметьте действие выполненным.']);
            }
            $action->fill($data);
            $changed = array_keys($action->getDirty());
            $action->save();
            $this->audit->record($tenant, new AuditEntry('vision.action.updated', 'vision_action', $action->id,
                ['status' => $before, 'responsible' => $previousResponsible], ['status' => $action->status, 'responsible' => $action->responsible, 'changed_fields' => $changed]));
        });
    }

    public function delete(TenantContext $tenant, Request $request, string $id): void
    {
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($tenant, $id): void {
            SiteAction::query()->whereKey($id)->firstOrFail();
            $this->access->lock($tenant, true, 'vision.actions.write');
            $action = SiteAction::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $action->delete();
            $this->audit->record($tenant, new AuditEntry('vision.action.deleted', 'vision_action', $id));
        });
    }

    private function responsible(TenantContext $tenant, ?string $id): void
    {
        if ($id === null) {
            return;
        }
        $member = Membership::withoutGlobalScopes()->whereKey($id)->where('organization_id', $tenant->organizationId)
            ->where('status', 'active')->lockForUpdate()->first();
        if (! $member || ! $member->permitsBrand($tenant->brandId)) {
            throw ValidationException::withMessages(['responsible' => 'Выберите доступного участника бренда.']);
        }
    }
}
