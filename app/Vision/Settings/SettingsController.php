<?php

namespace App\Vision\Settings;

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

                return ['id' => $member->user_id, 'name' => DB::table('users')->where('id', $member->user_id)->value('name'), 'cabinet' => (bool) ($channels['cabinet'] ?? true), 'email' => (bool) ($channels['email'] ?? false)];
            })->values();
            $approval = DB::table('vision_question_approvals')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('active', true)->first(['question_set_id']);
            $questionCount = $approval ? DB::table('question_sets')->where('id', $approval->question_set_id)->where('brand_id', $tenant->brandId)->value('size') : null;

            return Inertia::render('Vision/Settings/Index', ['revision' => $revision, 'schedule' => app(MeasurementSchedule::class)->read($tenant), 'events' => $events, 'members' => $members, 'canManageRecipients' => Gate::allows('vision.settings.write'), 'limits' => ['questions' => app(PlanLimits::class)->get($tenant, 'vision.questions')->value, 'models' => app(PlanLimits::class)->get($tenant, 'vision.models')->value], 'questionCount' => $questionCount]);
        }, false);
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
