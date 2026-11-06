<?php

namespace App\Vision\Settings;

use App\Ai\ProviderCatalog;
use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\EventCatalog;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\MonthlyUnits;
use App\Tenancy\TenantContext;
use App\Vision\Measurements\MeasurementList;
use App\Vision\Measurements\MeasurementSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class SettingsController
{
    private const EVENTS = ['vision.measurement.completed', 'vision.clarification.required', 'vision.report.updated', 'vision.action.overdue', 'vision.competitor.top5_added', 'vision.visibility.dropped'];

    public function index(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant, string $revision) use ($request): mixed {
            Gate::authorize('vision.report.read');
            $rows = DB::table('notification_settings')->where(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $tenant->actorId])->whereIn('event_key', self::EVENTS)->get(['event_key', 'channel', 'enabled']);
            $events = collect(self::EVENTS)->map(fn ($key) => ['key' => $key, 'title' => EventCatalog::TITLES[$key], 'channels' => array_replace(['cabinet' => true, 'email' => false, 'telegram' => false], $rows->where('event_key', $key)->mapWithKeys(fn ($row) => [$row->channel => (bool) $row->enabled])->all())])->all();
            $members = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('status', 'active')->get()->filter(fn ($member) => $member->permitsBrand($tenant->brandId))->map(function ($member) use ($tenant): array {
                $channels = DB::table('notification_settings')->where(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $member->user_id, 'event_key' => 'vision.report.updated'])->pluck('enabled', 'channel');

                return ['id' => $member->user_id, 'name' => DB::table('users')->where('id', $member->user_id)->value('name'), 'role' => $member->role, 'cabinet' => (bool) ($channels['cabinet'] ?? true), 'email' => (bool) ($channels['email'] ?? false)];
            })->values();
            $approval = DB::table('vision_question_approvals')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('active', true)->first(['question_set_id']);
            $questionCount = $approval ? DB::table('question_sets')->where('id', $approval->question_set_id)->where('brand_id', $tenant->brandId)->value('size') : null;

            $schedule = app(MeasurementSchedule::class)->read($tenant);
            $today = CarbonImmutable::now($schedule['timezone']);
            $period = $request->validate(['estimate_from' => ['sometimes', 'required', 'date_format:Y-m-d'], 'estimate_to' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:estimate_from']]);
            $from = $period['estimate_from'] ?? $today->startOfMonth()->toDateString();
            $to = $period['estimate_to'] ?? $today->endOfMonth()->toDateString();
            if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
                throw ValidationException::withMessages(['estimate_to' => 'Период оценки не может превышать 366 дней.']);
            }
            $estimator = app(ScheduleHistoryEstimate::class);
            $estimate = $estimator->estimate($tenant, $schedule, $questionCount === null ? null : (int) $questionCount, $from, $to);
            $monthEstimate = $estimator->estimate($tenant, $schedule, $questionCount === null ? null : (int) $questionCount, $today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString());

            $catalog = app(ProviderCatalog::class)->models();
            $modelOptions = collect(array_unique([...$schedule['available_models'], ...array_keys(array_filter($catalog, fn (array $model): bool => $model['status'] === 'planned'))]))
                ->map(fn (string $id): array => ['id' => $id, 'provider' => $catalog[$id]['provider'] ?? null, 'status' => $catalog[$id]['status'] ?? 'unavailable', 'approved' => in_array($id, $schedule['available_models'], true),
                    'description' => is_string($catalog[$id]['description'] ?? null) && trim($catalog[$id]['description']) !== '' ? trim($catalog[$id]['description']) : null])
                ->values()->all();

            $usedSlots = app(MonthlyUnits::class)->usage($tenant)['slots'];
            $limit = app(PlanLimits::class)->get($tenant, 'vision.slots_month')->value;
            $history = DB::table('measurements as m')->leftJoin('measurement_answers as a', function ($join): void {
                $join->on('a.measurement_id', '=', 'm.id')->on('a.organization_id', '=', 'm.organization_id')->on('a.brand_id', '=', 'm.brand_id');
            })->where('m.organization_id', $tenant->organizationId)->where('m.brand_id', $tenant->brandId)
                ->where('m.status', 'completed')->whereNotNull('m.finished_at')
                ->groupBy('m.id', 'm.finished_at')->orderByDesc('m.finished_at')->orderByDesc('m.id')->limit(20)
                ->get(['m.id', 'm.finished_at', DB::raw('count(a.id) as slots')])
                ->map(fn ($row) => ['id' => $row->id, 'finished_at' => $row->finished_at, 'slots' => (int) $row->slots])->all();
            $slotSummary = ['used' => $usedSlots, 'limit' => $limit, 'remaining' => is_numeric($limit) ? max(0, (int) floor((float) $limit) - $usedSlots) : null, 'history' => $history];

            $canManagePersonal = Gate::allows('vision.settings.write');
            $mayShowPersonalUrl = $canManagePersonal && $request->session()->get('personal_report_brand') === $tenant->brandId;
            $personalReports = [];
            $personalAccess = [];
            if ($canManagePersonal) {
                $personalReports = DB::table('vision_reports')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                    ->whereIn('measurement_id', DB::table('snapshot_confirmations')->where('organization_id', $tenant->organizationId)
                        ->where('brand_id', $tenant->brandId)->select('measurement_id'))
                    ->orderByDesc('created_at')->get(['id', 'revision', 'created_at'])->all();
                $personalAccess = DB::table('vision_personal_report_access as a')->join('report_publications as p', function ($join): void {
                    $join->on('p.id', '=', 'a.publication_id')->on('p.organization_id', '=', 'a.organization_id')->on('p.brand_id', '=', 'a.brand_id');
                })->where('a.organization_id', $tenant->organizationId)->where('a.brand_id', $tenant->brandId)
                    ->orderByDesc('a.created_at')->get(['a.id', 'a.name', 'a.email', 'p.report_id', 'p.expires_at', 'p.revoked_at', 'p.id as publication_id'])
                    ->map(function ($row) use ($tenant): array {
                        $opens = DB::table('audit_logs')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                            ->where('action', 'vision.report.publication.opened')->where('subject_type', 'report_publication')
                            ->where('subject_id', $row->publication_id)->orderByDesc('created_at')->pluck('created_at')->all();

                        return ['id' => $row->id, 'name' => $row->name, 'email' => $row->email, 'report_id' => $row->report_id,
                            'expires_at' => $row->expires_at, 'expired' => now()->greaterThanOrEqualTo($row->expires_at), 'revoked_at' => $row->revoked_at, 'opens' => $opens];
                    })->all();
                if ($mayShowPersonalUrl && $request->session()->has('personal_report_url')) {
                    Inertia::encryptHistory();
                }
            }

            return Inertia::render('Vision/Settings/Index', ['revision' => $revision, 'schedule' => $schedule, 'modelOptions' => $modelOptions, 'nextRuns' => app(SchedulePreview::class)->nextThree($schedule), 'events' => $events, 'members' => $members, 'canManageRecipients' => Gate::allows('vision.settings.write'), 'limits' => ['questions' => app(PlanLimits::class)->get($tenant, 'vision.questions')->value, 'models' => app(PlanLimits::class)->get($tenant, 'vision.models')->value], 'questionCount' => $questionCount, 'personalReports' => $personalReports, 'personalAccess' => $personalAccess, 'personalReportUrl' => $mayShowPersonalUrl ? $request->session()->get('personal_report_url') : null, 'slotSummary' => $slotSummary, 'slotEstimate' => ['from' => $from, 'to' => $to, 'period' => $estimate, 'month' => $monthEstimate]]);
        }, false);
    }

    public function models(Request $request): mixed
    {
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($request): mixed {
            Gate::authorize('vision.measure.run');
            $data = $request->validate([
                'models' => ['required', 'array', 'min:1', 'max:20'],
                'models.*' => ['required', 'string', 'max:120', 'distinct'],
            ]);
            try {
                app(MeasurementSchedule::class)->saveModels($tenant, $data['models']);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['models' => 'Выберите доступные модели текущего бренда.']);
            }

            return redirect()->route('vision.settings.index')->with('status', 'Модели сохранены.');
        });
    }

    public function recipients(Request $request): mixed
    {
        $input = $request->validate(['members' => ['required', 'array', 'max:100'], 'members.*.id' => ['required', 'ulid', 'distinct'], 'members.*.cabinet' => ['required', 'boolean'], 'members.*.email' => ['required', 'boolean']]);
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($input): void {
            Gate::authorize('vision.settings.write');
            DB::transaction(function () use ($tenant, $input): void {
                foreach ($input['members'] as $item) {
                    $member = Membership::withoutGlobalScopes()->where('organization_id', $tenant->organizationId)->where('user_id', $item['id'])->where('status', 'active')->first();
                    abort_unless($member && $member->permitsBrand($tenant->brandId), 403);
                    foreach (['cabinet', 'email'] as $channel) {
                        $where = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $member->user_id, 'event_key' => 'vision.report.updated', 'channel' => $channel];
                        $existing = DB::table('notification_settings')->where($where)->first(['id', 'created_at']);
                        DB::table('notification_settings')->updateOrInsert($where, ['id' => $existing?->id ?? (string) Str::ulid(), 'enabled' => $item[$channel], 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
                    }
                    app(AuditWriter::class)->record($tenant, new AuditEntry('vision.report.recipient.updated', 'user', $member->user_id, after: ['cabinet' => $item['cabinet'], 'email' => $item['email']]));
                }
            });
        });

        return redirect('/vision/settings')->with('status', 'Получатели сохранены.');
    }

    public function notifications(Request $request): mixed
    {
        $input = $request->validate(['changes' => ['required', 'array', 'max:18'], 'changes.*.event' => ['required', Rule::in(self::EVENTS)], 'changes.*.channel' => ['required', Rule::in(['cabinet', 'email', 'telegram'])], 'changes.*.enabled' => ['required', 'boolean']]);
        app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant) use ($input): void {
            foreach ($input['changes'] as $change) {
                if ($change['channel'] === 'telegram' && $change['enabled']) {
                    abort_unless(DB::table('profile_telegram_links')->where(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'user_id' => $tenant->actorId])->whereNotNull('chat_id')->exists(), 422, 'Сначала подключите Telegram в профиле.');
                }
                app(ConfigureNotification::class)->execute($tenant, $change['event'], $change['channel'], (bool) $change['enabled']);
            }
        });

        return redirect('/vision/settings')->with('status', 'Уведомления сохранены.');
    }
}
