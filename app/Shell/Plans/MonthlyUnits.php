<?php

namespace App\Shell\Plans;

use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class MonthlyUnits
{
    public function usage(TenantContext $context, ?string $excludeConversation = null): array
    {
        $organization = $context->requireOrganizationId();
        $start = CarbonImmutable::now('Europe/Moscow')->startOfMonth()->utc();
        $end = $start->setTimezone('Europe/Moscow')->addMonth()->startOfMonth()->utc();
        $dialogs = DB::table('conversations')->where('organization_id', $organization)->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->when($excludeConversation, fn ($query) => $query->where('id', '!=', $excludeConversation))->count();
        $slots = DB::table('measurement_answers as a')->join('measurements as m', function ($join): void {
            $join->on('m.id', '=', 'a.measurement_id')->on('m.organization_id', '=', 'a.organization_id')->on('m.brand_id', '=', 'a.brand_id');
        })->where('m.organization_id', $organization)->where('m.status', 'completed')->where('m.finished_at', '>=', $start)->where('m.finished_at', '<', $end)->count();

        return ['dialogs' => $dialogs, 'slots' => $slots, 'units' => $dialogs + $slots];
    }

    public function exhausted(TenantContext $context, string $action, ?string $excludeConversation = null): bool
    {
        $used = $this->usage($context, $excludeConversation);
        $keys = $action === 'dialog' ? ['seller.dialogs_month' => $used['dialogs'], 'ai.units_month' => $used['units']]
            : ['vision.slots_month' => $used['slots'], 'ai.units_month' => $used['units']];
        foreach ($keys as $key => $value) {
            if (app(LimitEnforcer::class)->exhausted($context, $key, $value)) {
                return true;
            }
        }

        return false;
    }
}
