<?php

namespace App\Shadow\Console;

use App\Models\Membership;
use App\Shadow\RecordShadowComparison;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecordShadowComparisonCommand extends Command
{
    protected $signature = 'shadow:record {brand} {channel} {inbound} {baseline} {candidate}';

    protected $description = 'Записать теневое сравнение ответа старого прототипа и нового движка';

    public function handle(RecordShadowComparison $recorder): int
    {
        if (! config('features.E9-03.defaults.shadow_enabled', false)) {
            $this->error('Теневой запуск не включён (нужно разрешение владельца на live shadow).');

            return self::FAILURE;
        }
        $brand = DB::table('brands')->where('id', $this->argument('brand'))->where('status', 'active')->first();
        if (! $brand) {
            $this->error('Активный бренд не найден.');

            return self::FAILURE;
        }
        $organization = DB::table('organizations')->where('id', $brand->organization_id)->where('status', 'active')->first();
        $membership = $organization ? Membership::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('user_id', $organization->owner_user_id)->where('status', 'active')->first() : null;
        if (! $membership || ! $membership->permitsBrand($brand->id)) {
            $this->error('Владелец организации не имеет доступа к бренду.');

            return self::FAILURE;
        }
        $tenant = new TenantContext($organization->id, $brand->id, $membership->user_id, $membership->id, source: 'job');
        $comparison = app(TenantContextRunner::class)->run($tenant, fn () => $recorder->record(
            $this->argument('channel'), $this->argument('inbound'), $this->argument('baseline'), $this->argument('candidate')
        ));
        $this->line('Вердикт: '.$comparison->verdict);
        if ($comparison->notes) {
            $this->line($comparison->notes);
        }

        return self::SUCCESS;
    }
}
