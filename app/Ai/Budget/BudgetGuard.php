<?php

namespace App\Ai\Budget;

use App\Contracts\Ai\AiContext;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Tracks only live (non-fake driver) spend, in ai_live_reservations. */
final class BudgetGuard
{
    /** Called inside Gateway::reserve()'s transaction, before any HTTP request. */
    public function assertReservationAllowed(AiContext $context, object $budget, int $reserved): void
    {
        $period = now('UTC')->startOfMonth()->toDateString();
        $today = now('UTC')->startOfDay()->toDateString();

        $daily = $this->dailyUsage();
        $this->guard('global_daily', $today, $today, $daily['spentMicros'] + $daily['reservedMicros'], $reserved, $daily['limitMicros'], null, null);

        $monthly = $this->monthlyUsage();
        $this->guard('global_monthly', 'global', $period, $monthly['spentMicros'] + $monthly['reservedMicros'], $reserved, $monthly['limitMicros'], null, null);

        $org = $this->monthlyUsage($context->organizationId);
        $this->guard('organization', $context->organizationId, $period, $org['spentMicros'] + $org['reservedMicros'], $reserved, $org['limitMicros'], $context, $budget);
    }

    public function orgMonthlyUsd(string $organizationId): float
    {
        $override = DB::table('ai_org_budget_overrides')->where('organization_id', $organizationId)->value('monthly_usd');

        return $override !== null ? (float) $override : (float) config('features.L1-01.defaults.org_monthly_usd');
    }

    public function usd(float $usd): int
    {
        return (int) round($usd * 1_000_000);
    }

    /** @return array{spentMicros:int,reservedMicros:int,limitMicros:int} */
    public function dailyUsage(): array
    {
        $dayStart = now('UTC')->startOfDay();
        $base = DB::table('ai_live_reservations')->where('released', false)->where('created_at', '>=', $dayStart);

        return [
            'spentMicros' => (int) (clone $base)->whereNotNull('spent_micros')->sum('spent_micros'),
            'reservedMicros' => (int) (clone $base)->whereNull('spent_micros')->sum('reserved_micros'),
            'limitMicros' => $this->usd((float) config('features.L1-01.defaults.global_daily_usd')),
        ];
    }

    /** @return array{spentMicros:int,reservedMicros:int,limitMicros:int} */
    public function monthlyUsage(?string $organizationId = null): array
    {
        $monthStart = now('UTC')->startOfMonth();
        $base = DB::table('ai_live_reservations')->where('released', false)->where('created_at', '>=', $monthStart);
        if ($organizationId !== null) {
            $base->where('organization_id', $organizationId);
        }

        return [
            'spentMicros' => (int) (clone $base)->whereNotNull('spent_micros')->sum('spent_micros'),
            'reservedMicros' => (int) (clone $base)->whereNull('spent_micros')->sum('reserved_micros'),
            'limitMicros' => $organizationId === null
                ? $this->usd((float) config('features.L1-01.defaults.global_monthly_usd'))
                : $this->usd($this->orgMonthlyUsd($organizationId)),
        ];
    }

    private function guard(string $scope, string $scopeKey, string $period, int $used, int $reserved, int $limit, ?AiContext $context, ?object $budget): void
    {
        if ($used + $reserved > $limit) {
            throw new BudgetExhausted($scope);
        }
        $threshold = (int) config('features.L1-01.defaults.budget_threshold_basis_points');
        if ($limit <= 0 || ($used + $reserved) * 10000 < $limit * $threshold) {
            return;
        }
        $notified = DB::table('ai_budget_threshold_notices')->where('scope', $scope)->where('scope_key', $scopeKey)->where('period', $period)->exists();
        if ($notified) {
            return;
        }
        DB::table('ai_budget_threshold_notices')->insertOrIgnore(['id' => (string) Str::ulid(), 'scope' => $scope, 'scope_key' => $scopeKey,
            'period' => $period, 'notified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Log::warning('ai.budget.threshold_reached', ['scope' => $scope, 'scope_key' => $scopeKey, 'used_micros' => $used + $reserved, 'limit_micros' => $limit]);
        if ($scope === 'organization' && $context !== null && $budget !== null) {
            app(NotificationDispatcher::class)->enqueue(new DomainEvent([
                'event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => 'ai.budget.threshold_reached',
                'occurred_at' => now()->toIso8601String(), 'organization_id' => $context->organizationId, 'brand_id' => $context->brandId,
                'actor_id' => app(TenantContext::class)->actorId, 'aggregate' => ['type' => 'ai_budget', 'id' => $budget->id, 'version' => 1],
                'data' => ['budget_id' => $budget->id, 'fraction' => intdiv($threshold, 10000).'.'.str_pad((string) ($threshold % 10000), 4, '0', STR_PAD_LEFT)],
            ]));
        }
    }
}
