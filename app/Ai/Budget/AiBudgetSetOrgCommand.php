<?php

namespace App\Ai\Budget;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AiBudgetSetOrgCommand extends Command
{
    protected $signature = 'ai:budget:set-org {organization} {usd}';

    protected $description = 'Переопределяет месячный лимит расходов на ИИ для организации';

    public function handle(): int
    {
        $organizationId = $this->argument('organization');
        $usd = $this->argument('usd');
        if (! Str::isUlid($organizationId) || ! is_numeric($usd) || (float) $usd < 0) {
            $this->error('Нужны организация (ulid) и неотрицательная сумма в USD.');

            return self::FAILURE;
        }
        if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
            $this->error('Организация не найдена.');

            return self::FAILURE;
        }
        $exists = DB::table('ai_org_budget_overrides')->where('organization_id', $organizationId)->exists();
        DB::table('ai_org_budget_overrides')->updateOrInsert(
            ['organization_id' => $organizationId],
            $exists ? ['monthly_usd' => (float) $usd, 'updated_at' => now()] : ['monthly_usd' => (float) $usd, 'created_at' => now(), 'updated_at' => now()],
        );
        $this->info(sprintf('Месячный лимит организации %s установлен: %.2f USD.', $organizationId, (float) $usd));

        return self::SUCCESS;
    }
}
