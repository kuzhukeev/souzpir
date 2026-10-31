<?php

namespace App\Seller\Settings\Limits;

use App\Shell\Plans\Contracts\PlanLimits;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class LimitsController
{
    public function index(TenantContext $context, PlanLimits $limits, UsageReader $usage): Response
    {
        Gate::authorize('seller.dialogs.read');
        $organization = $context->requireOrganizationId();
        $brand = $context->brandId ?? abort(404);
        abort_unless(app(SellerModuleAccess::class)->enabled($organization, $brand), 404);
        $from = CarbonImmutable::now('UTC')->startOfMonth();
        $to = $from->addMonth();
        $map = [
            ['key' => 'seller.dialogs_month', 'limit' => 'seller.dialogs_month', 'label' => 'Диалоги за месяц'],
            ['key' => 'seller.messages_month', 'limit' => 'seller.messages_month', 'label' => 'Сообщения клиентов и продавца'],
            ['key' => 'seller.channels_active', 'limit' => 'seller.channels_max', 'label' => 'Активные каналы'],
            ['key' => 'org.members_active', 'limit' => 'users.max', 'label' => 'Участники компании'],
        ];
        $snapshot = $usage->read($context, new UsageQuery($from, $to, array_column($map, 'key')));
        $rows = array_map(function (array $row) use ($limits, $context, $snapshot): array {
            $limit = $limits->get($context, $row['limit']);
            $metric = $snapshot->metrics[$row['key']];

            return ['key' => $row['key'], 'label' => $row['label'], 'value' => $metric['value'], 'status' => $metric['status'], 'limit' => $limit->value, 'configured' => $limit->configured];
        }, $map);
        $budget = DB::table('ai_budgets')->where('organization_id', $organization)->where('brand_id', $brand)->where('family', 'seller')->where('period', $from->toDateString())->first(['limit_units', 'used_units']);

        return Inertia::render('Seller/Settings/Limits/Index', [
            'rows' => $rows,
            'budget' => $budget ? ['limit' => $budget->limit_units, 'used' => $budget->used_units] : null,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => 'UTC'],
        ]);
    }
}
