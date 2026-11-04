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
use Illuminate\Support\Facades\Mail;
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
        $input = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'channel' => ['nullable', 'string', 'max:64'], 'source' => ['nullable', 'in:unknown'], 'manager' => ['nullable', 'ulid']]);
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
            ->get(['c.id', 'c.contact_id', 'c.stage', 'c.client_type', 'c.outcome', 'c.state', 'c.assigned_user_id', 'c.handed_off_at', 'c.taken_at', 'c.created_at', 'c.updated_at', 'a.type as channel', 'u.name as manager']);
        $canManage = Gate::forUser($user)->allows('seller.settings.write');
        $manager = $input['manager'] ?? '';
        abort_if($manager !== '' && ! $canManage, 403);
        $managerOptions = $canManage ? $rows->filter(fn ($row) => $row->assigned_user_id)->unique('assigned_user_id')->map(fn ($row) => ['id' => $row->assigned_user_id, 'name' => $row->manager ?? 'Участник'])->values()->all() : [];
        $channelOptions = $rows->map(fn ($row) => $row->channel ?? 'unknown')->unique()->sort()->values()->all();
        $channel = $input['channel'] ?? '';
        $source = $input['source'] ?? '';
        if ($channel !== '') {
            $rows = $rows->filter(fn ($row) => ($row->channel ?? 'unknown') === $channel)->values();
        }
        if ($manager !== '') {
            $rows = $rows->where('assigned_user_id', $manager)->values();
        }
        // The current intake does not persist source or UTM fields.
        $total = $rows->count();
        $outcomes = array_fill_keys(['full_contact', 'partial_contact', 'handoff', 'paused', 'declined', 'stale', 'spam'], 0);
        $types = array_fill_keys(['unknown', 'newbie', 'experienced', 'for_self', 'prices_only'], 0);
        $stages = [];
        $channels = [];
        $managers = [];
        $daily = [];
        $contact = 0;
        $handed = 0;
        $closed = 0;
        foreach ($rows as $row) {
            if (array_key_exists($row->outcome ?? '', $outcomes)) {
                $outcomes[$row->outcome]++;
            }
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
                $managers[$id] ??= ['name' => $row->manager ?? 'Участник', 'assigned' => 0, 'taken' => 0, 'closed' => 0, 'contact_closed' => 0, 'reaction_minutes' => [], 'workload' => []];
                $managers[$id]['assigned']++;
                if ($row->taken_at) {
                    $managers[$id]['taken']++;
                    $taken = CarbonImmutable::parse($row->taken_at);
                    $day = $taken->setTimezone($timezone)->format('Y-m-d');
                    if ($taken->greaterThanOrEqualTo($begin) && $taken->lessThan($end)) {
                        $managers[$id]['workload'][$day] = ($managers[$id]['workload'][$day] ?? 0) + 1;
                    }
                    if ($row->handed_off_at && $taken->greaterThanOrEqualTo(CarbonImmutable::parse($row->handed_off_at))) {
                        $managers[$id]['reaction_minutes'][] = CarbonImmutable::parse($row->handed_off_at)->diffInMinutes($taken);
                    }
                } if ($row->stage === 'done') {
                    $managers[$id]['closed']++;
                    if (! empty($slots['email']) || ! empty($slots['messenger'])) {
                        $managers[$id]['contact_closed']++;
                    }
                }
            }
        }
        ksort($daily);
        $ids = $rows->pluck('id');
        $messages = DB::table('messages')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('conversation_id', $ids)->get(['meta', 'conversation_id', 'role', 'created_at']);
        $messageRoles = $messages->groupBy('conversation_id');
        $botCompleted = $rows->filter(fn ($row) => $row->stage === 'done' && ! $row->handed_off_at
            && ($messageRoles[$row->id] ?? collect())->contains('role', 'agent')
            && ! ($messageRoles[$row->id] ?? collect())->contains('role', 'manager'))->count();
        $topics = [];
        $triggers = [];
        $triggerDialogs = [];
        $clientNames = DB::table('contacts')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('id', $rows->pluck('contact_id')->filter())->pluck('name', 'id');
        $messageCounts = $messages->groupBy('conversation_id')->map->count();
        $dialogs = $rows->map(function ($row) use ($messageCounts, $clientNames): array {
            $state = json_decode($row->state, true) ?: [];
            $slots = $state['slots'] ?? [];

            return ['id' => $row->id, 'client' => $clientNames[$row->contact_id] ?? null, 'stage' => $row->stage, 'channel' => $row->channel ?? 'unknown',
                'messages' => $messageCounts[$row->id] ?? 0, 'has_contact' => ! empty($slots['email']) || ! empty($slots['messenger']),
                'handed_off' => $row->handed_off_at !== null, 'last_activity' => $row->updated_at, 'created_at' => $row->created_at];
        })->values()->all();
        $hours = [];
        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row->created_at)->setTimezone($timezone);
            $key = $date->dayOfWeekIso.':'.$date->format('H');
            $hours[$key] = ($hours[$key] ?? 0) + 1;
        }
        $unknownTasks = DB::table('seller_tasks')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->whereIn('conversation_id', $ids)->where('type', 'unknown_question')
            ->get(['conversation_id', 'payload']);
        $unknownDialogs = $unknownTasks->pluck('conversation_id')->unique()->count();
        $unknownQuestions = $unknownTasks->map(function ($task) {
            $payload = json_decode($task->payload, true) ?: [];

            return is_string($payload['question'] ?? null) ? trim($payload['question']) : '';
        })->filter()->countBy()->sortDesc()->all();
        if ($canManage && $managers) {
            $reviews = DB::table('message_reviews as review')->join('messages as message', function ($join): void {
                $join->on('message.id', '=', 'review.message_id')->on('message.brand_id', '=', 'review.brand_id')->on('message.organization_id', '=', 'review.organization_id');
            })->where('review.organization_id', $tenant->organizationId)->where('review.brand_id', $tenant->brandId)
                ->whereIn('message.conversation_id', $ids)->where('review.created_at', '>=', $begin)->where('review.created_at', '<', $end)
                ->selectRaw('review.user_id, count(*) as total')->groupBy('review.user_id')->pluck('total', 'user_id');
            $evaluations = DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->whereIn('conversation_id', $ids)->get(['conversation_id', 'average'])->groupBy('conversation_id');
            foreach ($managers as $id => &$managerData) {
                $values = $rows->where('assigned_user_id', $id)->flatMap(fn ($row) => $evaluations[$row->id] ?? collect())->pluck('average')->filter(fn ($value) => is_numeric($value));
                $managerData['score'] = $values->isNotEmpty() ? round($values->avg(), 1) : null;
                $managerData['reviews'] = (int) ($reviews[$id] ?? 0);
                $managerData['reaction_minutes'] = $managerData['reaction_minutes'] ? round(array_sum($managerData['reaction_minutes']) / count($managerData['reaction_minutes']), 1) : null;
                ksort($managerData['workload']);
            }
            unset($managerData);
        }
        foreach ($messages as $message) {
            $meta = json_decode($message->meta, true) ?: [];
            $intent = data_get($meta, 'extraction.client_intent') ?? ($meta['intent'] ?? null);
            if (is_string($intent) && $intent !== '') {
                $topics[$intent] = ($topics[$intent] ?? 0) + 1;
            }
            $trigger = data_get($meta, 'goal.trigger');
            if (is_string($trigger) && $trigger !== '') {
                $triggers[$trigger] = ($triggers[$trigger] ?? 0) + 1;
                $triggerDialogs[$trigger][$message->conversation_id] = true;
            }
        }
        $triggerStats = [];
        foreach ($triggerDialogs as $trigger => $dialogIds) {
            $matching = $rows->whereIn('id', array_keys($dialogIds));
            $withContact = $matching->filter(function ($row): bool {
                $state = json_decode($row->state, true) ?: [];

                return ! empty($state['slots']['email']) || ! empty($state['slots']['messenger']);
            })->count();
            $triggerStats[$trigger] = ['dialogs' => $matching->count(), 'with_contact' => $withContact];
        }
        arsort($topics);

        $ratings = DB::table('seller_evaluations')->where('organization_id', $tenant->organizationId)
            ->where('brand_id', $tenant->brandId)->whereIn('conversation_id', $ids)
            ->orderByDesc('created_at')->orderByDesc('id')->get(['conversation_id', 'average'])
            ->unique('conversation_id')->keyBy('conversation_id');
        $channelMetrics = collect(['widget', 'telegram', 'whatsapp', 'api', ...array_keys($channels)])->unique()->map(function (string $name) use ($rows, $ratings): array {
            $items = $rows->filter(fn ($row): bool => ($row->channel ?? 'unknown') === $name);
            $contacts = $items->filter(function ($row): bool {
                $slots = (json_decode($row->state, true) ?: [])['slots'] ?? [];

                return ! empty($slots['email']) || ! empty($slots['messenger']);
            })->count();
            $scores = $items->map(fn ($row) => $ratings[$row->id]->average ?? null)->filter(fn ($score): bool => $score !== null);

            return ['channel' => $name, 'dialogs' => $items->count(), 'contacts' => $contacts,
                'conversion' => $items->count() ? round($contacts / $items->count() * 100, 1) : null,
                'average_rating' => $scores->isNotEmpty() ? round($scores->avg(), 1) : null,
                'planned' => $name === 'whatsapp'];
        })->values()->all();

        $events = DB::table('seller_stage_events')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->whereIn('conversation_id', $ids)->where('occurred_at', '>=', $begin)->where('occurred_at', '<', $end)->get(['conversation_id', 'to_stage']);
        $transitions = $events->groupBy('to_stage')->map(fn ($group) => $group->pluck('conversation_id')->unique()->count())->all();
        $repliedIds = $messages->groupBy('conversation_id')->filter(function ($items): bool {
            $agentSeen = false;
            foreach ($items->sortBy('created_at') as $message) {
                if ($message->role === 'agent') {
                    $agentSeen = true;
                } elseif ($message->role === 'client' && $agentSeen) {
                    return true;
                }
            }

            return false;
        })->keys()->all();
        $needIds = $events->where('to_stage', 'need')->pluck('conversation_id')->all();
        $replied = $rows->whereIn('id', $repliedIds);
        $need = $replied->filter(fn ($row) => in_array($row->id, $needIds, true) || in_array($row->stage, ['need', 'budget', 'close_contact', 'followup', 'handoff', 'done'], true));
        $withContact = $need->filter(function ($row): bool {
            $state = json_decode($row->state, true) ?: [];

            return ! empty($state['slots']['email']) || ! empty($state['slots']['messenger']);
        });
        $handedRows = $withContact->filter(fn ($row) => $row->handed_off_at !== null);
        $closedRows = $handedRows->filter(fn ($row) => $row->stage === 'done' && $row->assigned_user_id !== null);
        $funnel = [
            ['label' => 'Диалоги', 'count' => $total], ['label' => 'Ответили после бота', 'count' => $replied->count()],
            ['label' => 'Потребность ясна', 'count' => $need->count()], ['label' => 'Контакт получен', 'count' => $withContact->count()],
            ['label' => 'Передано менеджеру', 'count' => $handedRows->count()], ['label' => 'Закрыто менеджером', 'count' => $closedRows->count()],
        ];

        $days = (int) CarbonImmutable::parse($from, $timezone)->diffInDays(CarbonImmutable::parse($to, $timezone)) + 1;
        $previousBegin = CarbonImmutable::parse($from, $timezone)->subDays($days)->startOfDay()->utc();
        $previousQuery = DB::table('conversations as c')->leftJoin('channel_accounts as a', 'a.id', '=', 'c.channel_account_id')
            ->where('c.organization_id', $tenant->organizationId)->where('c.brand_id', $tenant->brandId)
            ->where('c.created_at', '>=', $previousBegin)->where('c.created_at', '<', $begin);
        if ($channel !== '') {
            $channel === 'unknown' ? $previousQuery->whereNull('a.type') : $previousQuery->where('a.type', $channel);
        }
        if ($manager !== '') {
            $previousQuery->where('c.assigned_user_id', $manager);
        }
        $previousRows = $previousQuery->get(['c.state', 'c.handed_off_at', 'c.stage']);
        $previousTotal = $previousRows->count();
        $previousMetrics = [
            'contact' => $previousRows->filter(function ($row): bool {
                $slots = (json_decode($row->state, true) ?: [])['slots'] ?? [];

                return ! empty($slots['email']) || ! empty($slots['messenger']);
            })->count(),
            'handed' => $previousRows->whereNotNull('handed_off_at')->count(),
            'closed' => $previousRows->where('stage', 'done')->count(),
        ];
        $stageGroups = $events->groupBy('to_stage')->map(fn ($group) => $group->pluck('conversation_id')->unique()->all());
        $rates = [];
        $orderedStages = ['open', 'qualify', 'need', 'budget', 'close_contact'];
        foreach ($orderedStages as $index => $stage) {
            if ($index === 0) {
                continue;
            }
            $prior = $stageGroups[$orderedStages[$index - 1]] ?? [];
            $current = $stageGroups[$stage] ?? [];
            $rates[$stage] = count($prior) ? round(count(array_intersect($prior, $current)) / count($prior) * 100, 1) : null;
        }

        return ['from' => $from, 'to' => $to, 'channel' => $channel, 'source' => $source, 'manager' => $manager, 'managerOptions' => $managerOptions, 'funnel' => $funnel, 'outcomes' => $outcomes, 'previousTotal' => $previousTotal, 'previousMetrics' => $previousMetrics, 'transitionRates' => $rates, 'channelOptions' => $channelOptions, 'sources' => ['unknown' => $total], 'channelMetrics' => $channelMetrics, 'sitePages' => [], 'transitions' => $transitions, 'total' => $total, 'contact' => $contact, 'handed' => $handed, 'closed' => $closed,
            'types' => $types, 'stages' => $stages, 'channels' => $channels, 'daily' => $daily, 'topics' => $topics,
            'dialogs' => $dialogs, 'hours' => $hours, 'unknownDialogs' => $unknownDialogs, 'unknownQuestions' => $unknownQuestions, 'messageCount' => $messages->count(), 'botCompleted' => $botCompleted, 'triggers' => $triggers, 'triggerStats' => $triggerStats,
            'managers' => array_values($managers), 'canManage' => Gate::forUser($user)->allows('seller.settings.write')];
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, fn (User $user, TenantContext $tenant) => $this->data($request, $user, $tenant));

        return Inertia::render('Seller/Analytics/Index', ['analytics' => $data]);
    }

    public function send(Request $request): mixed
    {
        $input = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'report_type' => ['required', 'in:weekly,dialogs,topics,managers']]);
        $data = $this->access($request, function (User $user, TenantContext $tenant) use ($request): array {
            Gate::forUser($user)->authorize('seller.settings.write');

            return $this->data($request, $user, $tenant);
        });
        $titles = ['weekly' => 'Сводка владельцу', 'dialogs' => 'Диалоги', 'topics' => 'Темы и вопросы', 'managers' => 'Воронка по менеджерам'];
        $title = $titles[$input['report_type']];
        $body = $title."\nПериод: {$data['from']} — {$data['to']}\nДиалогов: {$data['total']}\nС контактом: {$data['contact']}\nПередано: {$data['handed']}\nЗавершено: {$data['closed']}";
        if ($input['report_type'] === 'topics') {
            $body .= "\nТемы: ".collect($data['topics'])->map(fn ($count, $topic) => $topic.': '.$count)->take(10)->implode(', ');
            $body .= "\nДиалогов с неизвестными вопросами: ".$data['unknownDialogs'];
        } elseif ($input['report_type'] === 'dialogs') {
            $body .= "\nСообщений: ".$data['messageCount'];
            $body .= "\nСтадии: ".collect($data['stages'])->map(fn ($count, $stage) => $stage.': '.$count)->implode(', ');
        }
        if ($input['report_type'] === 'managers') {
            foreach ($data['managers'] as $manager) {
                $body .= "\n{$manager['name']}: назначено {$manager['assigned']}, принято {$manager['taken']}, завершено {$manager['closed']}";
            }
        }
        // Explicit in-memory transport; never deliver a real email from this action.
        Mail::mailer('array')->raw($body, fn ($message) => $message->to($input['email'])->subject('Тестовый отчёт: '.$title));

        return back()->with('status', 'Тестовый отчёт подготовлен.');
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
