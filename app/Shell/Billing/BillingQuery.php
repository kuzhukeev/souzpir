<?php

namespace App\Shell\Billing;

use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
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
                $result[] = ['date' => $day->format('Y-m-d'), 'key' => $key, 'label' => __('Shell/Billing/messages.metrics.'.$label), 'brand' => $brand, 'value' => $metric['value'], 'unit' => $metric['unit']];
            }
        }

        return ['brand' => $brand, 'rows' => $result, 'limited' => false];
    }

    public function read(TenantContext $tenant, string $tab, CarbonImmutable $from, CarbonImmutable $to, bool $canManage): array
    {
        $assigned = DB::table('organization_plan as assignment')->join('plans as plan', 'plan.id', '=', 'assignment.plan_id')
            ->where('assignment.organization_id', $tenant->organizationId)->where('assignment.starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('assignment.ends_at')->orWhere('assignment.ends_at', '>', now()))
            ->first(['plan.id', 'plan.name']);
        $limits = [];
        $keys = array_keys(self::LIMIT_LABELS);
        if ($assigned) {
            $keys = array_values(array_unique([...$keys, ...DB::table('plan_limits')->where('plan_id', $assigned->id)->orderBy('key')->pluck('key')->all()]));
        }
        foreach ($keys as $key) {
            $value = $this->limits->get($tenant, $key);
            $label = self::LIMIT_LABELS[$key] ?? 'other';
            $limits[] = ['key' => $key, 'label' => __('Shell/Billing/messages.limits.'.$label),
                'value' => $value->value, 'unit' => $value->unit, 'used' => null];
        }
        // UsageReader is the only cross-domain port. Do not treat unavailable adapters as zero.
        $snapshot = $this->usage->read($tenant, new UsageQuery($from, $to->addDay(), array_keys(self::METRICS)));
        $usage = [];
        foreach (self::METRICS as $key => $label) {
            $metric = $snapshot->metrics[$key] ?? ['value' => null, 'unit' => '', 'status' => 'unavailable'];
            $usage[] = ['key' => $key, 'label' => __('Shell/Billing/messages.metrics.'.$label), 'value' => $metric['value'], 'unit' => $metric['unit'], 'status' => $metric['status']];
        }
        $scope = SubmissionScope::for($tenant);
        $requestEnabled = $canManage && app()->bound(PlanRequestPort::class) && $scope !== null;
        $scope = $canManage ? $scope : null;

        return ['tab' => $tab, 'currentPlan' => $assigned ? ['id' => $assigned->id, 'name' => $assigned->name] : null,
            'limits' => $limits, 'usage' => $usage,
            'plans' => $canManage ? DB::table('plans')->where('is_public', true)->orderBy('name')->get(['id', 'name'])->toArray() : [],
            'canManage' => $canManage, 'requestEnabled' => $requestEnabled,
            'submissionScope' => $scope, 'submissionKey' => $requestEnabled ? (string) Str::ulid() : null,
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'daily' => $tab === 'usage' ? $this->daily($tenant, $from, $to) : null];
    }
}
