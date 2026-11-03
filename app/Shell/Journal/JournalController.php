<?php

namespace App\Shell\Journal;

use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Notifications\EventCatalog;
use App\Shell\Notifications\Jobs\DeliverNotification;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class JournalController
{
    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => [...['nullable', 'date_format:Y-m-d'], ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'actor' => ['nullable', 'ulid'],
            'module' => ['nullable', 'in:seller,vision,cabinet'],
            'action' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'in:cabinet,email,telegram'],
            'status' => ['nullable', 'in:pending,sending,sent,failed,uncertain'],
        ]);
    }

    private function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Moscow')->utc();
    }

    private function nextDayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Europe/Moscow')->addDay()->utc();
    }

    private function auditQuery(array $filters): Builder
    {
        $context = app(TenantContext::class);
        $editor = ! Gate::allows('journal.notifications.read');
        $query = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.organization_id', $context->requireOrganizationId())
            ->where(function (Builder $q) use ($context, $editor): void {
                $q->where('a.brand_id', $context->brandId);
                if (! $editor) {
                    $q->orWhereNull('a.brand_id');
                }
            });
        if ($editor) {
            $seller = Gate::allows('seller.knowledge.write');
            $vision = Gate::allows('vision.measure.run');
            $query->where(function (Builder $q) use ($seller, $vision): void {
                $q->whereRaw('1 = 0');
                if ($seller) {
                    $q->orWhere('a.action', 'like', 'knowledge.%')->orWhere('a.action', 'like', 'seller.%');
                }
                if ($vision) {
                    $q->orWhere('a.action', 'like', 'vision.%');
                }
            });
        }
        if (! empty($filters['from'])) {
            $query->where('a.created_at', '>=', $this->dayStart($filters['from'])->format('Y-m-d H:i:sP'));
        }
        if (! empty($filters['to'])) {
            $query->where('a.created_at', '<', $this->nextDayStart($filters['to'])->format('Y-m-d H:i:sP'));
        }
        if (! empty($filters['actor'])) {
            $query->where('a.actor_id', $filters['actor']);
        }
        if (! empty($filters['action'])) {
            $query->where('a.action', $filters['action']);
        }
        if (! empty($filters['module'])) {
            $module = $filters['module'];
            $query->where(function (Builder $q) use ($module): void {
                if ($module === 'seller') {
                    $q->where('a.action', 'like', 'knowledge.%')->orWhere('a.action', 'like', 'seller.%');
                } elseif ($module === 'vision') {
                    $q->where('a.action', 'like', 'vision.%');
                } else {
                    $q->where('a.action', 'not like', 'knowledge.%')->where('a.action', 'not like', 'seller.%')->where('a.action', 'not like', 'vision.%');
                }
            });
        }

        return $query->select('a.id', 'a.created_at', 'a.action', 'a.subject_type', 'a.subject_id', 'a.brand_id', 'a.before', 'a.after', 'u.name as actor')->orderByDesc('a.created_at')->orderByDesc('a.id');
    }

    public function index(Request $request)
    {
        Gate::authorize('journal.read');
        $filters = $this->filters($request);
        $rows = $this->auditQuery($filters)->paginate(30)->withQueryString();
        $context = app(TenantContext::class);
        $registry = app(JournalObjectRegistry::class);
        $rows->through(function (object $row) use ($context, $registry): object {
            $object = $registry->describe($row, $context);
            $row->subject_name = $object['name'];
            $row->subject_url = $object['url'];
            $row->module_name = $registry->module($row->action);
            $row->action_label = $registry->action($row->action);

            return $row;
        });
        $actors = $this->auditQuery([])->reorder()->whereNotNull('a.actor_id')->select('a.actor_id as id', 'u.name')->distinct()->orderBy('u.name')->get();
        $actions = $this->auditQuery([])->reorder()->select('a.action')->distinct()->orderBy('a.action')->pluck('action');

        return Inertia::render('Shell/Journal/Actions', ['rows' => $rows, 'filters' => $filters, 'actors' => $actors, 'actions' => $actions, 'canNotifications' => Gate::allows('journal.notifications.read')]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('journal.read');
        $query = $this->auditQuery($this->filters($request));

        $context = app(TenantContext::class);

        return response()->streamDownload(function () use ($query, $context): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Время', 'Участник', 'Действие', 'Тип объекта', 'Объект', 'Бренд', 'Модуль'], ';', '"', '');
            $registry = app(JournalObjectRegistry::class);
            foreach ($query->cursor() as $row) {
                $safe = fn ($value) => $this->csvCell((string) ($value ?? ''));
                $object = $registry->describe($row, $context);
                fputcsv($out, [$safe($row->created_at), $safe($row->actor), $safe($registry->action($row->action)), $safe($row->subject_type), $safe($object['name']), $safe($row->brand_id), $safe($registry->module($row->action))], ';', '"', '');
            }
            fclose($out);
        }, 'journal.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $value) ? "'".$value : $value;
    }

    public function notifications(Request $request)
    {
        Gate::authorize('journal.notifications.read');
        $context = app(TenantContext::class);
        $filters = $this->filters($request);
        $query = DB::table('notification_deliveries as d')->join('outbox_events as e', function ($join): void {
            $join->on('e.id', '=', 'd.event_id')->on('e.organization_id', '=', 'd.organization_id');
        })->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.organization_id', $context->requireOrganizationId())->where('d.brand_id', $context->brandId);
        if (! empty($filters['from'])) {
            $query->where('d.created_at', '>=', $this->dayStart($filters['from'])->format('Y-m-d H:i:sP'));
        }
        if (! empty($filters['to'])) {
            $query->where('d.created_at', '<', $this->dayStart($filters['to'])->addDay());
        }
        if (! empty($filters['channel'])) {
            $query->where('d.channel', $filters['channel']);
        }
        if (! empty($filters['status'])) {
            $query->where('d.status', $filters['status']);
        }
        $rows = $query->select('d.id', 'd.created_at', 'd.channel', 'd.status', 'd.attempts', 'u.name as recipient', 'e.envelope')->orderByDesc('d.created_at')->orderByDesc('d.id')->paginate(30)->withQueryString();
        $rows->getCollection()->transform(function ($row): array {
            $event = json_decode($row->envelope, true, 512, JSON_THROW_ON_ERROR);

            return ['id' => $row->id, 'created_at' => $row->created_at, 'channel' => $row->channel, 'status' => $row->status, 'attempts' => $row->attempts, 'recipient' => $row->recipient, 'title' => EventCatalog::TITLES[$event['type']] ?? 'Уведомление'];
        });

        return Inertia::render('Shell/Journal/Notifications', ['rows' => $rows, 'filters' => $filters]);
    }

    public function retry(Request $request, string $id)
    {
        Gate::authorize('journal.retry');
        $context = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $id): void {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->where('id', $context->membershipId)->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            if (! in_array($member->role, ['owner', 'admin'], true) || ! $member->permitsBrand($context->brandId)) {
                abort(403);
            }
            $delivery = DB::table('notification_deliveries')->where('id', $id)->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->lockForUpdate()->first() ?? abort(404);
            if ($delivery->status !== 'failed' || ! in_array($delivery->channel, ['email', 'telegram'], true)) {
                abort(409, 'Повтор этой доставки недоступен.');
            }
            DB::table('notification_deliveries')->where('id', $id)->update(['status' => 'pending', 'last_error' => null, 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('notification.delivery.retry_requested', 'notification_delivery', $id));
            // The database job and delivery state must commit in the same transaction.
            // Never fall back to a separate broker after committing the state.
            $queueConnection = config('queue.connections.database.connection');
            if (config('queue.connections.database.driver') !== 'database' || config('queue.connections.database.after_commit') !== false || ($queueConnection !== null && $queueConnection !== DB::getDefaultConnection())) {
                abort(503, 'Очередь уведомлений не настроена для этой базы.');
            }
            Queue::connection('database')->push(new DeliverNotification($id));
        });

        return back()->with('status', 'Доставка поставлена в очередь.');
    }
}
