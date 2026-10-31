<?php

namespace App\Seller\Settings\Limits;

use App\Shell\Plans\Contracts\UsageContributor;
use App\Shell\Plans\Contracts\UsageQuery;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class SellerUsage implements UsageContributor
{
    public function read(TenantContext $context, UsageQuery $query): array
    {
        $requested = array_flip($query->metrics);
        $sellerMetrics = ['seller.dialogs_month', 'seller.dialogues', 'seller.messages_month', 'seller.channels_active'];
        $needsBrand = count(array_intersect($sellerMetrics, $query->metrics)) > 0;
        if (! $needsBrand && ! isset($requested['org.members_active'])) {
            return [];
        }
        $organization = $context->requireOrganizationId();
        if (! DB::table('organizations')->where('id', $organization)->where('status', 'active')->exists()) {
            abort(404);
        }
        $brand = $context->brandId;
        if ($needsBrand && (! $brand || ! DB::table('brands')->where('id', $brand)->where('organization_id', $organization)->where('status', 'active')->exists())) {
            abort(404);
        }
        $out = [];
        $scoped = static fn (string $table) => DB::table($table)->where('organization_id', $organization)->where('brand_id', $brand);
        $from = $query->from->format('Y-m-d H:i:sP');
        $to = $query->to->format('Y-m-d H:i:sP');
        if (isset($requested['seller.dialogs_month']) || isset($requested['seller.dialogues'])) {
            $dialogues = ['value' => $scoped('conversations')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(), 'unit' => 'dialogues', 'status' => 'ready'];
            foreach (['seller.dialogs_month', 'seller.dialogues'] as $key) {
                if (isset($requested[$key])) {
                    $out[$key] = $dialogues;
                }
            }
        }
        if (isset($requested['seller.messages_month'])) {
            $out['seller.messages_month'] = ['value' => $scoped('messages')->whereIn('role', ['client', 'agent'])->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(), 'unit' => 'messages', 'status' => 'ready'];
        }
        if (isset($requested['seller.channels_active']) && $query->from <= now() && $query->to > now()) {
            $out['seller.channels_active'] = ['value' => $scoped('channel_accounts')->where('status', 'active')->count(), 'unit' => 'channels', 'status' => 'ready'];
        }
        if (isset($requested['org.members_active']) && $query->from <= now() && $query->to > now()) {
            $out['org.members_active'] = ['value' => DB::table('memberships')->where('organization_id', $organization)->where('status', 'active')->count(), 'unit' => 'members', 'status' => 'ready'];
        }

        return $out;
    }
}
