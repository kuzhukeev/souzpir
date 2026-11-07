<?php

namespace App\Seller\Settings\Schedule;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeNotReady;
use App\Seller\Runtime\ServicePhraseSelector;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ScheduleController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    private function read(TenantContext $tenant): array
    {
        $raw = DB::table('seller_schedule_preferences')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->value('payload');
        $data = $raw ? json_decode($raw, true) : [];

        return array_replace(['mode' => 'autonomous', 'week' => [], 'holidays' => [], 'assignment' => ['method' => 'first_taken', 'fixed' => null, 'members' => [], 'notify_minutes' => null, 'notify_target' => 'all_managers']], is_array($data) ? $data : []);
    }

    private function save(TenantContext $tenant, array $data): void
    {
        $row = DB::table('seller_schedule_preferences')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->first();
        $id = $row?->id ?? (string) Str::ulid();
        DB::table('seller_schedule_preferences')->updateOrInsert(['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId],
            ['id' => $id, 'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => $row?->created_at ?? now(), 'updated_at' => now()]);
        app(AuditWriter::class)->record($tenant, new AuditEntry('seller.schedule.settings.updated', 'seller_schedule_preferences', $id));
    }

    private function members(TenantContext $tenant): array
    {
        return Membership::withoutGlobalScopes()->with('user')->where('organization_id', $tenant->organizationId)->where('status', 'active')->get()->filter(function (Membership $member) use ($tenant): bool {
            if (! $member->user || ! $member->permitsBrand($tenant->brandId)) {
                return false;
            }
            $actor = new TenantContext($tenant->organizationId, $tenant->brandId, $member->user_id, $member->id);

            return app(TenantContextRunner::class)->run($actor, fn () => Gate::forUser($member->user)->allows('seller.dialogues.operate'));
        })->map(fn (Membership $member) => ['id' => $member->user_id, 'name' => $member->user->name])->values()->all();
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (User $user, TenantContext $tenant): array {
            $timezone = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('timezone');
            try {
                $persona = app(KnowledgeRepository::class)->published($tenant)->persona();
                $outOfHours = app(ServicePhraseSelector::class)->forEvent($persona, 'out_of_hours');
            } catch (KnowledgeNotReady) {
                $outOfHours = null;
            }

            return ['outOfHoursPhrase' => $outOfHours, 'settings' => $this->read($tenant), 'members' => $this->members($tenant), 'timezone' => $timezone];
        });

        return Inertia::render('Seller/Settings/Schedule/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function updateSchedule(Request $request): mixed
    {
        $input = $request->validate(['mode' => ['required', 'in:autonomous,suggest,off'], 'week' => ['present', 'array'], 'week.*.enabled' => ['required', 'boolean'],
            'week.*.start' => ['required', 'date_format:H:i'], 'week.*.end' => ['required', 'date_format:H:i'], 'week.*.behavior' => ['required', 'in:bot,quiet,off'],
            'holidays' => ['present', 'array', 'max:100'], 'holidays.*' => ['required', 'date_format:Y-m-d']]);
        if ($input['week'] !== [] && count($input['week']) !== 7) {
            throw ValidationException::withMessages(['week' => 'Нужны настройки для семи дней.']);
        }
        foreach ($input['week'] as $day) {
            if ($day['start'] >= $day['end']) {
                throw ValidationException::withMessages(['week' => 'Время окончания должно быть позже начала.']);
            }
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $data = array_replace($this->read($tenant), $input);
            $this->save($tenant, $data);
        });

        return back()->with('status', 'Расписание сохранено.');
    }

    public function updateAssignment(Request $request): mixed
    {
        $input = $request->validate(['method' => ['required', 'in:first_taken,round_robin,fixed'], 'fixed' => ['nullable', 'ulid'], 'members' => ['present', 'array', 'max:100'],
            'members.*.id' => ['required', 'ulid'], 'members.*.enabled' => ['required', 'boolean'], 'members.*.max_active' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'members.*.hours' => ['nullable', 'array'], 'members.*.hours.start' => ['required_with:members.*.hours', 'date_format:H:i'], 'members.*.hours.end' => ['required_with:members.*.hours', 'date_format:H:i'],
            'members.*.channels' => ['nullable', 'array'], 'members.*.channels.*' => ['in:telegram,widget,api,email,whatsapp'],
            'notify_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'], 'notify_target' => ['required', 'in:all_managers,owner']]);
        foreach ($input['members'] as $entry) {
            if (is_array($entry['hours'] ?? null) && $entry['hours']['start'] >= $entry['hours']['end']) {
                throw ValidationException::withMessages(['members' => 'Время окончания должно быть позже начала.']);
            }
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $ids = array_column($this->members($tenant), 'id');
            if (array_diff(array_column($input['members'], 'id'), $ids) || (! empty($input['fixed']) && ! in_array($input['fixed'], $ids, true))) {
                throw ValidationException::withMessages(['members' => 'Выбран недоступный участник.']);
            }
            if ($input['method'] === 'fixed' && empty($input['fixed'])) {
                throw ValidationException::withMessages(['fixed' => 'Выберите менеджера.']);
            }
            $data = $this->read($tenant);
            $data['assignment'] = $input;
            $this->save($tenant, $data);
        });

        return back()->with('status', 'Распределение сохранено.');
    }
}
