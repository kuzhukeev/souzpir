<?php

namespace App\Seller\Catalog;

use App\Models\Membership;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RefreshYmlFeeds extends Command
{
    protected $signature = 'seller:refresh-yml-feeds';

    protected $description = 'Обновить каталоги по расписанию';

    public function handle(YmlFeedStore $store, YmlRefresh $refresh): int
    {
        foreach (DB::table('brands')->where('status', 'active')->get(['id', 'organization_id']) as $brand) {
            $settings = $store->read($brand->organization_id, $brand->id);
            if (! $settings || empty($settings['interval_hours']) || ! app(SellerModuleAccess::class)->enabled($brand->organization_id, $brand->id)) {
                continue;
            }
            $member = Membership::withoutGlobalScopes()->with('user')->where('organization_id', $brand->organization_id)->where('user_id', $settings['user_id'])->where('status', 'active')->first();
            if (! $member || ! $member->permitsBrand($brand->id)) {
                continue;
            }
            if (! empty($settings['last_run_at']) && CarbonImmutable::parse($settings['last_run_at'])->addHours((int) $settings['interval_hours'])->isFuture()) {
                continue;
            }
            $tenant = new TenantContext($brand->organization_id, $brand->id, $member->user_id, $member->id, source: 'job');
            if (! $member->user || ! app(TenantContextRunner::class)->run($tenant, fn () => Gate::forUser($member->user)->allows('seller.settings.write'))) {
                continue;
            }
            $refresh->run($tenant, $settings);
            $settings['last_run_at'] = now()->toIso8601String();
            $store->write($brand->organization_id, $brand->id, $settings);
        }

        return self::SUCCESS;
    }
}
