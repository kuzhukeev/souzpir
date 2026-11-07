<?php

namespace App\Ai\Budget;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class AiBudgetCommand extends Command
{
    protected $signature = 'ai:budget';

    protected $description = 'Показывает расход, резерв и лимит ИИ за сегодня и за месяц — глобально и по организациям';

    public function handle(BudgetGuard $guard): int
    {
        $usd = static fn (int $micros): string => sprintf('%.2f', $micros / 1_000_000);
        $daily = $guard->dailyUsage();
        $monthly = $guard->monthlyUsage();
        $this->line('Глобально сегодня: расход '.$usd($daily['spentMicros']).' USD, резерв '.$usd($daily['reservedMicros']).' USD, лимит '.$usd($daily['limitMicros']).' USD');
        $this->line('Глобально за месяц: расход '.$usd($monthly['spentMicros']).' USD, резерв '.$usd($monthly['reservedMicros']).' USD, лимит '.$usd($monthly['limitMicros']).' USD');
        $organizations = DB::table('ai_live_reservations')->distinct()->pluck('organization_id');
        foreach ($organizations as $organizationId) {
            $org = $guard->monthlyUsage($organizationId);
            $this->line('Организация '.$organizationId.' за месяц: расход '.$usd($org['spentMicros']).' USD, резерв '.$usd($org['reservedMicros']).' USD, лимит '.$usd($org['limitMicros']).' USD');
        }

        return self::SUCCESS;
    }
}
