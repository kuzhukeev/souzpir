<?php

namespace App\Seller\Settings\Schedule;

use App\Models\Membership;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AssignmentCandidates
{
    public function select(TenantContext $tenant, array $settings, ?string $last = null): ?string
    {
        $eligible = [];
        foreach ($settings['members'] ?? [] as $entry) {
            if (! ($entry['enabled'] ?? false) || ! is_string($entry['id'] ?? null)) {
                continue;
            }
            $member = Membership::withoutGlobalScopes()->with('user')->where('organization_id', $tenant->organizationId)->where('user_id', $entry['id'])->where('status', 'active')->first();
            if (! $member || ! $member->user || ! $member->permitsBrand($tenant->brandId)) {
                continue;
            }
            $actor = new TenantContext($tenant->organizationId, $tenant->brandId, $member->user_id, $member->id);
            if (! app(TenantContextRunner::class)->run($actor, fn () => Gate::forUser($member->user)->allows('seller.dialogues.operate'))) {
                continue;
            }
            $active = DB::table('conversations')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)
                ->where('assigned_user_id', $member->user_id)->whereNotIn('stage', ['done', 'lost', 'spam'])->count();
            if (($entry['max_active'] ?? null) === null || $active < (int) $entry['max_active']) {
                $eligible[] = $member->user_id;
            }
        }

        return app(AssignmentSelector::class)->select($settings['method'] ?? 'first_taken', $eligible, $last, $settings['fixed'] ?? null);
    }
}
