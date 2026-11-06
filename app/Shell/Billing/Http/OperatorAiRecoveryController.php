<?php

namespace App\Shell\Billing\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class OperatorAiRecoveryController
{
    public function __invoke(Request $request, string $brand): Response
    {
        $user = $request->user();
        $operatorId = getenv('AI_RECOVERY_OPERATOR_USER_ID');
        abort_unless($user && is_string($operatorId) && $operatorId !== '' && hash_equals($operatorId, (string) $user->id), 403);
        abort_unless(Str::isUlid($brand), 404);
        $brandRow = DB::table('brands')->where('id', $brand)->first(['id', 'organization_id']);
        abort_unless($brandRow, 404);

        $costs = DB::table('ai_calls')
            ->where('organization_id', $brandRow->organization_id)
            ->where('brand_id', $brandRow->id)
            ->whereNotNull('reconciliation_evidence_hash')
            ->whereNotNull('cost_micros')
            ->whereIn('status', ['completed', 'failed'])
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(50)
            ->get(['id', 'cost_micros'])
            ->map(fn ($call) => ['id' => $call->id, 'usd' => sprintf('%d.%06d', intdiv((int) $call->cost_micros, 1000000), (int) $call->cost_micros % 1000000)])
            ->all();

        return Inertia::render('Shell/Billing/OperatorAiRecovery', ['brandId' => $brandRow->id, 'confirmedCosts' => $costs]);
    }
}
