<?php

namespace App\Seller\Analytics;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class AnalyticsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.dialogs.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function data(Request $request, User $user, TenantContext $tenant): array
    {
        $input = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone') ?: 'UTC';
        $today = CarbonImmutable::now($timezone);
        $from = $input['from'] ?? $today->subDays(29)->format('Y-m-d');
        $to = $input['to'] ?? $today->format('Y-m-d');
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => 'Конец периода должен быть не раньше начала.']);
        }
        $begin = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc();
        $rows = DB::table('conversations as c')->leftJoin('channel_accounts as a', 'a.id', '=', 'c.channel_account_id')->leftJoin('users as u', 'u.id', '=', 'c.assigned_user_id')
            ->where('c.organization_id', $tenant->organizationId)->where('c.brand_id', $tenant->brandId)->where('c.created_at', '>=', $begin)->where('c.created_at', '<', $end)
            ->get(['c.id', 'c.stage', 'c.client_type', 'c.outcome', 'c.state', 'c.assigned_user_id', 'c.handed_off_at', 'c.taken_at', 'c.created_at', 'a.type as channel', 'u.name as manager']);
        $total = $rows->count();
        $types = array_fill_keys(['unknown', 'newbie', 'experienced', 'for_self', 'prices_only'], 0);
        $stages = [];
        $channels = [];
        $managers = [];
        $daily = [];
        $contact = 0;
        $handed = 0;
        $closed = 0;
        foreach ($rows as $row) {
            $type = array_key_exists($row->client_type, $types) ? $row->client_type : 'unknown';
            $types[$type]++;
            $stages[$row->stage] = ($stages[$row->stage] ?? 0) + 1;
            $channels[$row->channel ?? 'unknown'] = ($channels[$row->channel ?? 'unknown'] ?? 0) + 1;
            $day = CarbonImmutable::parse($row->created_at)->setTimezone($timezone)->format('Y-m-d');
            $daily[$day] = ($daily[$day] ?? 0) + 1;
            $state = json_decode($row->state, true) ?: [];
            $slots = $state['slots'] ?? [];
            if (! empty($slots['email']) || ! empty($slots['messenger'])) {
                $contact++;
            }
            if ($row->handed_off_at) {
                $handed++;
            }
            if ($row->stage === 'done') {
                $closed++;
            }
            if ($row->assigned_user_id && Gate::forUser($user)->allows('seller.settings.write')) {
                $id = $row->assigned_user_id;
                $managers[$id] ??= ['name' => $row->manager ?? 'Участник', 'assigned' => 0, 'taken' => 0, 'closed' => 0];
                $managers[$id]['assigned']++;
                if ($row->taken_at) {
                    $managers[$id]['taken']++;
                } if ($row->stage === 'done') {
                    $managers[$id]['closed']++;
                }
            }
        }
        ksort($daily);
        $ids = $rows->pluck('id');
        $messages = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('conversation_id', $ids)->get(['meta']);
        $topics = [];
        foreach ($messages as $message) {
            $meta = json_decode($message->meta, true) ?: [];
            $intent = $meta['intent'] ?? null;
            if (is_string($intent) && $intent !== '') {
                $topics[$intent] = ($topics[$intent] ?? 0) + 1;
            }
        }
        arsort($topics);

        return ['from' => $from, 'to' => $to, 'total' => $total, 'contact' => $contact, 'handed' => $handed, 'closed' => $closed,
            'types' => $types, 'stages' => $stages, 'channels' => $channels, 'daily' => $daily, 'topics' => $topics,
            'managers' => array_values($managers), 'canManage' => Gate::forUser($user)->allows('seller.settings.write')];
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, fn (User $user, TenantContext $tenant) => $this->data($request, $user, $tenant));

        return Inertia::render('Seller/Analytics/Index', ['analytics' => $data]);
    }

    public function csv(Request $request): mixed
    {
        $data = $this->access($request, fn (User $user, TenantContext $tenant) => $this->data($request, $user, $tenant));

        return response()->streamDownload(function () use ($data): void {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Показатель', 'Значение'], ';', '"', '');
            foreach (['Диалоги' => 'total', 'Контакт получен' => 'contact', 'Переданы менеджеру' => 'handed', 'Завершены' => 'closed'] as $label => $key) {
                fputcsv($file, [$label, $data[$key]], ';', '"', '');
            }
            fclose($file);
        }, 'seller-analytics.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
