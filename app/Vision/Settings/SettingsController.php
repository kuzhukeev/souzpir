<?php

namespace App\Vision\Settings;

use App\Ai\ProviderCatalog;
use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\ConfigureNotification;
use App\Shell\Notifications\EventCatalog;
use App\Shell\Plans\Contracts\PlanLimits;
use App\Tenancy\TenantContext;
use App\Vision\Measurements\MeasurementList;
use App\Vision\Measurements\MeasurementSchedule;
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
        return app(MeasurementList::class)->withinCurrent($request, function (TenantContext $tenant, string $revision): mixed {
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

            $catalog = app(ProviderCatalog::class)->models();
            $modelOptions = collect(array_unique([...$schedule['available_models'], ...array_keys(array_filter($catalog, fn (array $model): bool => $model['status'] === 'planned'))]))
                ->map(fn (string $id): array => ['id' => $id, 'provider' => $catalog[$id]['provider'] ?? null, 'status' => $catalog[$id]['status'] ?? 'unavailable', 'approved' => in_array($id, $schedule['available_models'], true)])
                ->values()->all();

            return Inertia::render('Vision/Settings/Index', ['revision' => $revision, 'schedule' => $schedule, 'modelOptions' => $modelOptions, 'nextRuns' => app(SchedulePreview::class)->nextThree($schedule), 'events' => $events, 'members' => $members, 'canManageRecipients' => Gate::allows('vision.settings.write'), 'limits' => ['questions' => app(PlanLimits::class)->get($tenant, 'vision.questions')->value, 'models' => app(PlanLimits::class)->get($tenant, 'vision.models')->value], 'questionCount' => $questionCount]);
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
