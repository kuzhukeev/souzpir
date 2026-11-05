<?php

namespace App\Seller\Dashboard;

use App\Seller\Evaluation\EvaluationVisibility;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DashboardQuery
{
    public function read(TenantContext $tenant, int $days): array
    {
        $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone') ?: 'UTC';
        $today = CarbonImmutable::now($timezone);
        $start = $today->subDays($days - 1)->startOfDay()->utc();
        $rows = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('created_at', '>=', $start)->where('created_at', '<', $today->addDay()->startOfDay()->utc())
            ->get(['id', 'stage', 'state', 'handed_off_at', 'taken_at', 'created_at', 'channel_account_id']);
        $daily = [];
        $dailyContacts = [];
        for ($day = $days - 1; $day >= 0; $day--) {
            $daily[$today->subDays($day)->format('Y-m-d')] = 0;
            $dailyContacts[$today->subDays($day)->format('Y-m-d')] = 0;
        }
        $contact = $handoff = $done = $need = 0;
        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->created_at)->setTimezone($timezone)->format('Y-m-d');
            if (array_key_exists($date, $daily)) {
                $daily[$date]++;
            }
            $state = json_decode($row->state, true) ?: [];
            $slots = $state['slots'] ?? [];
            if (! empty($slots['email']) || ! empty($slots['messenger'])) {
                $contact++;
                if (array_key_exists($date, $dailyContacts)) {
                    $dailyContacts[$date]++;
                }
            }
            $handoff += (int) ($row->handed_off_at !== null);
            $done += (int) ($row->stage === 'done');
            $need += (int) (! in_array($row->stage, ['open', 'spam', 'lost'], true));
        }
        $previous = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->where('created_at', '>=', $start->subDays($days))->where('created_at', '<', $start)
            ->get(['stage', 'state', 'handed_off_at']);
        $previousContact = $previous->filter(function ($row): bool {
            $state = json_decode($row->state, true) ?: [];

            return ! empty($state['slots']['email']) || ! empty($state['slots']['messenger']);
        })->count();
        $delta = ['total' => $rows->count() - $previous->count(), 'contact' => $contact - $previousContact,
            'handoff' => $handoff - $previous->whereNotNull('handed_off_at')->count(), 'done' => $done - $previous->where('stage', 'done')->count()];
        $scope = fn (string $table) => DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
        $end = $today->addDay()->startOfDay()->utc();
        $evaluations = $scope('seller_evaluations')->where(fn ($query) => EvaluationVisibility::excludeTestBaseline($query))->where('created_at', '>=', $start)->where('created_at', '<', $end)->get(['average', 'issues']);
        $quality = $evaluations->isEmpty() ? null : round((float) $evaluations->avg('average'), 2);
        $issues = [];
        foreach ($evaluations as $evaluation) {
            foreach (json_decode($evaluation->issues, true) ?: [] as $issue) {
                $type = is_array($issue) ? ($issue['type'] ?? null) : null;
                if (is_string($type) && $type !== '') {
                    $issues[$type] = ($issues[$type] ?? 0) + 1;
                }
            }
        }
        arsort($issues);
        $issues = array_slice($issues, 0, 5, true);
        $previousEvaluations = $scope('seller_evaluations')->where(fn ($query) => EvaluationVisibility::excludeTestBaseline($query))->where('created_at', '>=', $start->subDays($days))->where('created_at', '<', $start)->get(['issues']);
        $priorIssues = [];
        foreach ($previousEvaluations as $evaluation) {
            $seen = [];
            foreach (json_decode($evaluation->issues, true) ?: [] as $issue) {
                if (is_array($issue) && is_string($issue['type'] ?? null)) {
                    $seen[$issue['type']] = true;
                }
            }
            foreach (array_keys($seen) as $type) {
                $priorIssues[$type] = ($priorIssues[$type] ?? 0) + 1;
            }
        }
        $issueDetails = [];
        foreach ($issues as $type => $count) {
            $affected = $evaluations->filter(function ($evaluation) use ($type): bool {
                return collect(json_decode($evaluation->issues, true) ?: [])->contains(fn ($item) => is_array($item) && ($item['type'] ?? null) === $type);
            })->count();
            $share = $evaluations->count() ? round(100 * $affected / $evaluations->count(), 1) : null;
            $previousShare = $previousEvaluations->count() ? 100 * ($priorIssues[$type] ?? 0) / $previousEvaluations->count() : null;
            $issueDetails[] = ['type' => $type, 'share' => $share, 'delta' => $previousShare === null ? null : round($share - $previousShare, 1), 'href' => '/seller/quality'];
        }
        $topics = [];
        try {
            $intentTitles = collect(app(KnowledgeRepository::class)->published($tenant)->intents())
                ->filter(fn ($intent) => is_array($intent) && is_string($intent['id'] ?? null) && is_string($intent['title'] ?? null) && trim($intent['title']) !== '')
                ->pluck('title', 'id')->all();
        } catch (KnowledgeNotReady) {
            $intentTitles = [];
        }
        foreach ($scope('messages')->where('created_at', '>=', $start)->where('created_at', '<', $end)->get(['meta']) as $message) {
            $meta = json_decode($message->meta, true) ?: [];
            $intent = $meta['intent'] ?? null;
            if (is_string($intent) && isset($intentTitles[$intent])) {
                $title = $intentTitles[$intent];
                $topics[$title] = ($topics[$title] ?? 0) + 1;
            }
        }
        arsort($topics);
        $topics = array_slice($topics, 0, 5, true);
        $managers = DB::table('conversations as c')->join('users as u', 'u.id', '=', 'c.assigned_user_id')
            ->where('c.organization_id', $tenant->organizationId)->where('c.brand_id', $tenant->brandId)->where('c.mode', 'manager')
            ->where('c.created_at', '>=', $start)->where('c.created_at', '<', $end)
            ->select('u.id', 'u.name')->selectRaw("count(*) as total, sum(case when c.handed_off_at is not null then 1 else 0 end) as handed, sum(case when c.stage = 'done' then 1 else 0 end) as closed")
            ->groupBy('u.id', 'u.name')->orderByDesc('total')->limit(10)->get()
            ->map(fn ($row): array => ['id' => $row->id, 'name' => $row->name, 'total' => (int) $row->total, 'handed' => (int) $row->handed, 'closed' => (int) $row->closed]);
        $waiting = $scope('conversations')->where('mode', 'manager')->whereNull('assigned_user_id')->count();
        $overdue = $scope('seller_tasks')->where('status', 'new')->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $channels = $scope('channel_accounts')->orderBy('type')->get(['id', 'type', 'name', 'default_mode', 'status'])->map(function ($channel) use ($rows): array {
            $items = $rows->where('channel_account_id', $channel->id);
            $contacts = $items->filter(function ($item): bool {
                $state = json_decode($item->state, true) ?: [];

                return ! empty($state['slots']['email']) || ! empty($state['slots']['messenger']);
            })->count();

            return ['type' => $channel->type, 'name' => $channel->name ?: $channel->type, 'mode' => $channel->default_mode, 'status' => $channel->status,
                'dialogs' => $items->count(), 'contacts' => $contacts, 'conversion' => $items->isEmpty() ? null : round(100 * $contacts / $items->count(), 1)];
        })->all();
        $attention = [];
        if ($waiting > 0) {
            $oldest = $scope('conversations')->where('mode', 'manager')->whereNull('assigned_user_id')->whereNotNull('handed_off_at')->min('handed_off_at');
            $detail = $oldest ? 'Ожидание до '.max(0, (int) CarbonImmutable::parse($oldest)->diffInMinutes(now())).' мин · '.$waiting : (string) $waiting;
            $attention[] = ['kind' => 'manager', 'title' => 'Диалоги ждут менеджера', 'count' => $waiting, 'detail' => $detail, 'severity' => 'high', 'href' => '/seller/dialogs?mode=manager'];
        }
        if ($overdue > 0) {
            $attention[] = ['kind' => 'tasks', 'title' => 'Обещания просрочены', 'count' => $overdue, 'severity' => 'high', 'href' => '/seller/tasks'];
        }
        $disabled = collect($channels)->where('status', 'disabled')->count();
        if ($disabled > 0) {
            $attention[] = ['kind' => 'channels', 'title' => 'Каналы выключены', 'count' => $disabled, 'severity' => 'normal', 'href' => '/seller/channels'];
        }

        $attention = array_merge($attention, app(AttentionEvents::class)->read($tenant, $rows->count(), $contact, $previous->count(), $previousContact));

        return ['days' => $days, 'total' => $rows->count(), 'contact' => $contact, 'handoff' => $handoff, 'done' => $done,
            'need' => $need, 'daily' => $daily, 'daily_contacts' => $dailyContacts, 'channels' => $channels, 'attention' => $attention, 'delta' => $delta, 'quality' => $quality, 'issues' => $issues, 'issue_details' => $issueDetails, 'topics' => $topics, 'managers' => $managers];
    }
}
