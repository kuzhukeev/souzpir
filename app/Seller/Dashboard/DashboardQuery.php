<?php

namespace App\Seller\Dashboard;

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
            ->get(['id', 'stage', 'state', 'handed_off_at', 'taken_at', 'created_at']);
        $daily = [];
        for ($day = $days - 1; $day >= 0; $day--) {
            $daily[$today->subDays($day)->format('Y-m-d')] = 0;
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
        $evaluations = $scope('seller_evaluations')->where('created_at', '>=', $start)->where('created_at', '<', $end)->get(['average', 'issues']);
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
        $topics = [];
        foreach ($scope('messages')->where('created_at', '>=', $start)->where('created_at', '<', $end)->get(['meta']) as $message) {
            $meta = json_decode($message->meta, true) ?: [];
            $intent = $meta['intent'] ?? null;
            if (is_string($intent) && $intent !== '') {
                $topics[$intent] = ($topics[$intent] ?? 0) + 1;
            }
        }
        arsort($topics);
        $topics = array_slice($topics, 0, 5, true);
        $managers = DB::table('conversations as c')->join('users as u', 'u.id', '=', 'c.assigned_user_id')
            ->where('c.organization_id', $tenant->organizationId)->where('c.brand_id', $tenant->brandId)->where('c.mode', 'manager')
            ->select('u.id', 'u.name', DB::raw('count(*) as total'))->groupBy('u.id', 'u.name')->orderByDesc('total')->limit(10)->get();
        $waiting = $scope('conversations')->where('mode', 'manager')->whereNull('assigned_user_id')->count();
        $overdue = $scope('seller_tasks')->where('status', 'new')->whereNotNull('due_at')->where('due_at', '<', now())->count();
        $channels = $scope('channel_accounts')->orderBy('type')->get(['type', 'name', 'default_mode', 'status'])->map(fn ($row) => [
            'type' => $row->type, 'name' => $row->name ?: $row->type, 'mode' => $row->default_mode, 'status' => $row->status,
        ])->all();
        $attention = [];
        if ($waiting > 0) {
            $attention[] = ['kind' => 'manager', 'title' => 'Диалоги ждут менеджера', 'count' => $waiting, 'severity' => 'high', 'href' => '/seller/dialogs?mode=manager'];
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
            'need' => $need, 'daily' => $daily, 'channels' => $channels, 'attention' => $attention, 'delta' => $delta, 'quality' => $quality, 'issues' => $issues, 'topics' => $topics, 'managers' => $managers];
    }
}
