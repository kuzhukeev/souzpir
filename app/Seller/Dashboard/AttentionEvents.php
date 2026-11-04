<?php

namespace App\Seller\Dashboard;

use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AttentionEvents
{
    public function read(TenantContext $tenant, int $total, int $contact, int $previousTotal, int $previousContact): array
    {
        $scope = fn (string $table) => DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        $events = [];
        $add = function (string $kind, string $title, int $count, string $severity, string $href, string $detail) use (&$events): void {
            $events[] = compact('kind', 'title', 'count', 'severity', 'href', 'detail');
        };
        $unknown = $scope('seller_tasks')->where('type', 'unknown_question')->where('status', 'new')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('audit_logs')
                ->whereColumn('audit_logs.organization_id', 'seller_tasks.organization_id')
                ->whereColumn('audit_logs.brand_id', 'seller_tasks.brand_id')
                ->whereColumn('audit_logs.subject_id', 'seller_tasks.id')
                ->where('audit_logs.action', 'knowledge.incoming.handled')->where('audit_logs.subject_type', 'task'))->count();
        if ($unknown) {
            $add('unknown', 'Неизвестные вопросы', $unknown, 'normal', '/seller/knowledge', (string) $unknown);
        }
        $draft = $scope('knowledge_releases')->where('status', 'draft')->first(['id', 'created_at']);
        if ($draft) {
            $data = json_decode(DB::table('kn_conditions')->where('release_id', $draft->id)->value('data') ?? '{}', true) ?: [];
            $unapproved = 0;
            foreach (['min_order_rub', 'offseason_min_order_rub', 'reserve_days', 'new_client_bonus_pct'] as $field) {
                if (array_key_exists($field, $data) && empty($data['approvals'][$field])) {
                    $unapproved++;
                }
            }
            if ($unapproved) {
                $add('conditions', 'Условия не утверждены', $unapproved, 'normal', '/seller/knowledge/conditions', (string) $unapproved);
            }
            if (CarbonImmutable::parse($draft->created_at)->lt(now()->subDays(7))) {
                $age = (int) CarbonImmutable::parse($draft->created_at)->diffInDays(now());
                $add('knowledge', 'Версия знаний не опубликована', $age, 'normal', '/seller/knowledge', "{$age} дн.");
            }
        }
        if ($total >= 5 && $previousTotal >= 5 && $previousContact > 0) {
            $drop = (int) round((1 - ($contact / $total) / ($previousContact / $previousTotal)) * 100);
            if ($drop >= 20) {
                $add('conversion', 'Конверсия в контакт снизилась', $drop, 'high', '/seller/analytics', "−{$drop}%");
            }
        }
        $report = $scope('seller_supervisor_reports')->orderByDesc('report_date')->orderByDesc('created_at')->first(['issues']);
        $issues = $report ? json_decode($report->issues, true) ?: [] : [];
        $blocking = count(array_filter($issues, fn ($issue) => is_array($issue) && ($issue['severity'] ?? null) === 'high' && empty($issue['completed'])));
        if ($blocking) {
            $add('report', 'Блокирующие проблемы в докладе', $blocking, 'high', '/seller/quality', (string) $blocking);
        }
        $limit = app(PlanLimits::class)->get($tenant, 'seller.dialogs_month');
        if ($limit->configured && is_numeric($limit->value) && (float) $limit->value > 0) {
            $from = CarbonImmutable::now('UTC')->startOfMonth();
            $metric = app(UsageReader::class)->read($tenant, new UsageQuery($from, $from->addMonth(), ['seller.dialogs_month']))->metrics['seller.dialogs_month'];
            if ($metric['status'] === 'ready' && is_numeric($metric['value'])) {
                $percent = (int) floor((float) $metric['value'] / (float) $limit->value * 100);
                if ($percent >= 80) {
                    $add('limit', 'Лимит диалогов', $percent, 'high', '/seller/settings/limits', "{$percent}%");
                }
            }
        }

        return $events;
    }
}
