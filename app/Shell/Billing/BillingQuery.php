<?php

namespace App\Shell\Billing;

use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Shell\Plans\MonthlyUnits;
use App\Shell\Support\Contracts\PlanRequestPort;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BillingQuery
{
    private const METRICS = [
        'seller.dialogues' => 'seller_dialogues',
        'vision.measurements' => 'vision_measurements',
        'ai.calls' => 'ai_calls',
        'ai.tokens' => 'ai_tokens',
        'vision.slots' => 'vision_slots',
    ];

    private const LIMIT_LABELS = [
        'brands.max' => 'brands_max',
        'seller.dialogs_month' => 'seller_dialogs_month',
        'vision.runs_per_week' => 'vision_runs_per_week',
        'ai.units_month' => 'ai_units_month',
    ];

    public function __construct(private PlanLimits $limits, private UsageReader $usage) {}

    public function daily(TenantContext $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $brand = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->value('name');
        $result = [];
        $plan = $this->assignedPlan($tenant);
        $terms = $plan ? (config('billing.plans', [])[$plan->id] ?? []) : [];
        if ($plan && isset(config('billing.example_plans', [])[$plan->code])) {
            $terms['units_per_event'] = config('billing.example_units_per_event');
        }
        if ($from->diffInDays($to) > 92 || ! $brand) {
            return ['brand' => $brand, 'rows' => [], 'limited' => $from->diffInDays($to) > 92];
        }
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $snapshot = $this->usage->read($tenant, new UsageQuery($day, $day->addDay(), array_keys(self::METRICS)));
            foreach (self::METRICS as $key => $label) {
                $metric = $snapshot->metrics[$key] ?? null;
                if (($metric['status'] ?? null) !== 'ready' || (float) $metric['value'] == 0.0) {
                    continue;
                }
                $rate = $terms['units_per_event'][$key] ?? null;
                $units = is_numeric($rate) && $rate >= 0 ? round((float) $metric['value'] * (float) $rate, 6) : null;
                $price = $terms['unit_price'] ?? null;
                $cost = $units !== null && is_numeric($price) && $price >= 0 ? round($units * (float) $price, 6) : null;
                $result[] = ['date' => $day->format('Y-m-d'), 'key' => $key, 'label' => $key === 'vision.slots' ? 'Слоты' : __('Shell/Billing/messages.metrics.'.$label), 'brand' => $brand, 'value' => $metric['value'], 'unit' => $metric['unit'], 'tariffUnits' => $units, 'tariffCost' => $cost];
            }
        }

        return ['brand' => $brand, 'rows' => $result, 'limited' => false];
    }

    public function read(TenantContext $tenant, string $tab, CarbonImmutable $from, CarbonImmutable $to, bool $canManage): array
    {
        $assigned = $this->assignedPlan($tenant);
        $limits = [];
        $keys = array_keys(self::LIMIT_LABELS);
        if ($assigned) {
            $keys = array_values(array_unique([...$keys, ...DB::table('plan_limits')->where('plan_id', $assigned->id)->orderBy('key')->pluck('key')->all()]));
        }
        foreach ($keys as $key) {
            $value = $this->limits->get($tenant, $key);
            $label = self::LIMIT_LABELS[$key] ?? 'other';
            $exampleLabels = ['vision.questions' => 'Вопросы', 'vision.models' => 'Модели на бренд', 'vision.slots_month' => 'Слоты в месяц', 'users.max' => 'Участники', 'seller.channels_max' => 'Каналы'];
            $limits[] = ['key' => $key, 'label' => $exampleLabels[$key] ?? __('Shell/Billing/messages.limits.'.$label),
                'value' => $value->value, 'unit' => $value->unit, 'used' => $this->used($tenant, $key)];
        }
        // UsageReader is the only cross-domain port. Do not treat unavailable adapters as zero.
        $snapshot = $this->usage->read($tenant, new UsageQuery($from, $to->addDay(), array_keys(self::METRICS)));
        $usage = [];
        foreach (self::METRICS as $key => $label) {
            $metric = $snapshot->metrics[$key] ?? ['value' => null, 'unit' => '', 'status' => 'unavailable'];
            $usage[] = ['key' => $key, 'label' => $key === 'vision.slots' ? 'Слоты' : __('Shell/Billing/messages.metrics.'.$label), 'value' => $metric['value'], 'unit' => $metric['unit'], 'status' => $metric['status']];
        }
        $scope = SubmissionScope::for($tenant);
        $requestEnabled = $canManage && app()->bound(PlanRequestPort::class) && $scope !== null;
        $scope = $canManage ? $scope : null;

        $renewal = $assigned && isset(config('billing.example_plans', [])[$assigned->code])
            ? CarbonImmutable::now('Europe/Moscow')->startOfMonth()->addMonth()->toDateString() : null;

        return ['tab' => $tab, 'currentPlan' => $assigned ? ['id' => $assigned->id, 'code' => $assigned->code, 'name' => $assigned->name, 'startsAt' => $assigned->starts_at, 'endsAt' => $assigned->ends_at, 'renewsAt' => config('billing.renewals', [])[$tenant->organizationId][$assigned->id] ?? $renewal] : null,
            'limits' => $limits, 'usage' => $usage, 'examplePlans' => config('billing.example_plans', []),
            'plans' => $canManage ? DB::table('plans')->where('is_public', true)->orderBy('name')->get(['id', 'name'])->toArray() : [],
            'canManage' => $canManage, 'requestEnabled' => $requestEnabled,
            'submissionScope' => $scope, 'submissionKey' => $requestEnabled ? (string) Str::ulid() : null,
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'daily' => $tab === 'usage' ? $this->daily($tenant, $from, $to) : null];
    }

    private function used(TenantContext $tenant, string $key): ?int
    {
        if (in_array($key, ['ai.units_month', 'vision.slots_month', 'seller.dialogs_month'], true)) {
            $usage = app(MonthlyUnits::class)->usage($tenant);

            return $usage[match ($key) {
                'ai.units_month' => 'units', 'vision.slots_month' => 'slots', default => 'dialogs'
            }];
        }
        if ($key === 'brands.max') {
            return DB::table('brands')->where('organization_id', $tenant->organizationId)->where('status', 'active')->count();
        }
        if ($key === 'vision.runs_per_week') {
            $start = CarbonImmutable::now('Europe/Moscow')->startOfWeek();

            return DB::table('measurements')->where('organization_id', $tenant->organizationId)->where('status', 'completed')->where('finished_at', '>=', $start->utc())->where('finished_at', '<', $start->addWeek()->utc())->count();
        }
        $metric = match ($key) {
            'seller.dialogs_month' => 'seller.dialogs_month',
            'vision.runs_per_week' => 'vision.measurements',
            default => null,
        };
        if ($metric === null) {
            return null;
        }
        $now = CarbonImmutable::now('Europe/Moscow');
        $start = ($key === 'vision.runs_per_week' ? $now->startOfWeek() : $now->startOfMonth())->utc();
        $result = $this->usage->read($tenant, new UsageQuery($start, $now->addSecond()->utc(), [$metric]))->metrics[$metric] ?? null;

        return ($result['status'] ?? null) === 'ready' ? (int) $result['value'] : null;
    }

    private function assignedPlan(TenantContext $tenant): ?object
    {
        return DB::table('organization_plan as assignment')->join('plans as plan', 'plan.id', '=', 'assignment.plan_id')
            ->where('assignment.organization_id', $tenant->organizationId)->where('assignment.starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('assignment.ends_at')->orWhere('assignment.ends_at', '>', now()))
            ->first(['plan.id', 'plan.code', 'plan.name', 'assignment.starts_at', 'assignment.ends_at']);
    }
}
