<?php

namespace App\Shell\Billing\Http;

use App\Shell\Profile\ProfileAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AiRecoveryPageController
{
    public function __invoke(Request $request, ProfileAccess $access): Response
    {
        $costs = $access->run($request, function ($user, $tenant, $brand, $member, $organization): ?array {
            Gate::forUser($user)->authorize('billing.manage');
            abort_unless($tenant->source === 'session' && $member->role === 'owner'
                && $organization->owner_user_id === $user->id, 403);

            // Platform configuration is outside the tenant's control; default is no operator.
            $configuredId = getenv('AI_RECOVERY_OPERATOR_USER_ID');
            if (! is_string($configuredId) || $configuredId === '' || ! hash_equals($configuredId, (string) $user->id)) {
                return null;
            }

            return DB::table('ai_calls')
                ->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)
                ->whereNotNull('reconciliation_evidence_hash')
                ->whereNotNull('cost_micros')
                ->whereIn('status', ['completed', 'failed'])
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(50)
                ->get(['id', 'cost_micros'])
                ->map(fn ($call) => ['id' => $call->id, 'usd' => sprintf('%d.%06d', intdiv((int) $call->cost_micros, 1000000), (int) $call->cost_micros % 1000000)])
                ->all();
        });

        $props = ['aiRecoveryRevision' => $request->attributes->get('profile.brand_revision')];
        if ($costs !== null) {
            $props['confirmedCosts'] = $costs;
        }

        return Inertia::render('Shell/Billing/AiRecovery', $props);
    }
}
