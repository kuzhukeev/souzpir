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
    public const TIMEZONES = ['Europe/Kaliningrad', 'Europe/Moscow', 'Europe/Samara', 'Asia/Yekaterinburg', 'Asia/Omsk', 'Asia/Krasnoyarsk', 'Asia/Irkutsk', 'Asia/Yakutsk', 'Asia/Vladivostok', 'Asia/Magadan', 'Asia/Kamchatka'];

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

        return array_replace(['mode' => 'autonomous', 'week' => [], 'holidays' => [], 'channel_overrides' => [], 'assignment' => ['method' => 'first_taken', 'fixed' => null, 'fixed_channels' => [], 'fixed_sources' => [], 'city_rules' => [], 'members' => [], 'notify_minutes' => null, 'notify_target' => 'all_managers']], is_array($data) ? $data : []);
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

    private const CHANNEL_LABELS = ['widget' => 'Сайт', 'telegram' => 'Telegram', 'api' => 'API', 'email' => 'Email', 'whatsapp' => 'WhatsApp'];

    /** @return list<array{id:string,label:string}> brand channel connections — the «source» of a dialogue */
    private function sources(TenantContext $tenant): array
    {
        return DB::table('channel_accounts')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
            ->orderBy('type')->orderBy('created_at')->get(['id', 'type', 'name'])
            ->map(fn (object $row) => ['id' => $row->id, 'label' => (self::CHANNEL_LABELS[$row->type] ?? $row->type).(is_string($row->name) && trim($row->name) !== '' ? ' · '.trim($row->name) : '')])
            ->values()->all();
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

            $cities = DB::table('contacts')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->whereNotNull('city')->where('city', '<>', '')->distinct()->orderBy('city')->limit(200)->pluck('city')->all();

            return ['outOfHoursPhrase' => $outOfHours, 'settings' => $this->read($tenant), 'members' => $this->members($tenant), 'timezone' => $timezone, 'timezones' => self::TIMEZONES,
                'sources' => $this->sources($tenant), 'cities' => $cities];
        });

        return Inertia::render('Seller/Settings/Schedule/Index', [...$data, 'status' => $request->session()->get('status')]);
    }

    public function updateSchedule(Request $request): mixed
    {
        $input = $request->validate(['mode' => ['required', 'in:autonomous,suggest,off'], 'week' => ['present', 'array'], 'week.*.enabled' => ['required', 'boolean'],
            'week.*.start' => ['required', 'date_format:H:i'], 'week.*.end' => ['required', 'date_format:H:i'], 'week.*.behavior' => ['required', 'in:bot,quiet,off'],
            'holidays' => ['present', 'array', 'max:100'], 'holidays.*' => ['required', 'date_format:Y-m-d'],
            'channel_overrides' => ['sometimes', 'array'], 'channel_overrides.telegram' => ['nullable', 'in:autonomous,suggest,off'],
            'channel_overrides.api' => ['nullable', 'in:autonomous,suggest,off'],
            'timezone' => ['nullable', 'in:'.implode(',', self::TIMEZONES)]]);
        if (array_key_exists('channel_overrides', $input)) {
            $input['channel_overrides'] = array_filter($input['channel_overrides'], fn ($mode) => $mode !== null);
        }
        if ($input['week'] !== [] && count($input['week']) !== 7) {
            throw ValidationException::withMessages(['week' => 'Нужны настройки для семи дней.']);
        }
        foreach ($input['week'] as $day) {
            if ($day['start'] >= $day['end']) {
                throw ValidationException::withMessages(['week' => 'Время окончания должно быть позже начала.']);
            }
        }
        $timezone = $input['timezone'] ?? null;
        unset($input['timezone']);
        $this->access($request, function (User $user, TenantContext $tenant) use ($input, $timezone): void {
            $data = array_replace($this->read($tenant), $input);
            $this->save($tenant, $data);
            if ($timezone !== null) {
                DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->update(['timezone' => $timezone, 'updated_at' => now()]);
            }
        });

        return back()->with('status', 'Расписание сохранено.');
    }

    public function updateAssignment(Request $request): mixed
    {
        $input = $request->validate(['method' => ['required', 'in:first_taken,round_robin,fixed,city'], 'fixed' => ['nullable', 'ulid'], 'members' => ['present', 'array', 'max:100'],
            'members.*.id' => ['required', 'ulid'], 'members.*.enabled' => ['required', 'boolean'], 'members.*.max_active' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'members.*.hours' => ['nullable', 'array'], 'members.*.hours.start' => ['required_with:members.*.hours', 'date_format:H:i'], 'members.*.hours.end' => ['required_with:members.*.hours', 'date_format:H:i'],
            'members.*.channels' => ['nullable', 'array'], 'members.*.channels.*' => ['in:telegram,widget,api,email,whatsapp'],
            'fixed_channels' => ['nullable', 'array'], 'fixed_channels.*' => ['nullable', 'ulid'],
            'fixed_sources' => ['nullable', 'array', 'max:100'], 'fixed_sources.*' => ['nullable', 'ulid'],
            'city_rules' => ['nullable', 'array', 'max:100'], 'city_rules.*.city' => ['required', 'string', 'max:120'], 'city_rules.*.user_id' => ['required', 'ulid'],
            'notify_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'], 'notify_target' => ['required', 'in:all_managers,owner']]);
        foreach ($input['members'] as $entry) {
            if (is_array($entry['hours'] ?? null) && $entry['hours']['start'] >= $entry['hours']['end']) {
                throw ValidationException::withMessages(['members' => 'Время окончания должно быть позже начала.']);
            }
        }
        if (array_diff(array_keys($input['fixed_channels'] ?? []), ['telegram', 'widget', 'api', 'email', 'whatsapp'])) {
            throw ValidationException::withMessages(['fixed_channels' => 'Неизвестный канал.']);
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($input): void {
            $ids = array_column($this->members($tenant), 'id');
            $fixedChannelIds = array_filter($input['fixed_channels'] ?? []);
            $fixedSourceIds = array_filter($input['fixed_sources'] ?? []);
            $cityRules = [];
            foreach ($input['city_rules'] ?? [] as $rule) {
                $city = trim($rule['city']);
                if ($city === '') {
                    continue;
                }
                if (array_key_exists(AssignmentCandidates::normalizeCity($city), $cityRules)) {
                    throw ValidationException::withMessages(['city_rules' => 'Город указан дважды: '.$city.'.']);
                }
                $cityRules[AssignmentCandidates::normalizeCity($city)] = ['city' => $city, 'user_id' => $rule['user_id']];
            }
            $cityRules = array_values($cityRules);
            if (array_diff(array_column($input['members'], 'id'), $ids) || (! empty($input['fixed']) && ! in_array($input['fixed'], $ids, true)) || array_diff($fixedChannelIds, $ids)
                || array_diff($fixedSourceIds, $ids) || array_diff(array_column($cityRules, 'user_id'), $ids)) {
                throw ValidationException::withMessages(['members' => 'Выбран недоступный участник.']);
            }
            if (array_diff(array_keys($fixedSourceIds), array_column($this->sources($tenant), 'id'))) {
                throw ValidationException::withMessages(['fixed_sources' => 'Неизвестный источник.']);
            }
            if ($input['method'] === 'fixed' && empty($input['fixed'])) {
                throw ValidationException::withMessages(['fixed' => 'Выберите менеджера.']);
            }
            if ($input['method'] === 'city' && $cityRules === []) {
                throw ValidationException::withMessages(['city_rules' => 'Добавьте хотя бы один город.']);
            }
            $input['fixed_channels'] = $fixedChannelIds;
            $input['fixed_sources'] = $fixedSourceIds;
            $input['city_rules'] = $cityRules;
            $data = $this->read($tenant);
            $data['assignment'] = $input;
            $this->save($tenant, $data);
        });

        return back()->with('status', 'Распределение сохранено.');
    }
}
