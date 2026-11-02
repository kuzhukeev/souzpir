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
        $scope = fn (string $table) => DB::table($table)->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId);
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

        return ['days' => $days, 'total' => $rows->count(), 'contact' => $contact, 'handoff' => $handoff, 'done' => $done,
            'need' => $need, 'daily' => $daily, 'channels' => $channels, 'attention' => $attention];
    }
}
