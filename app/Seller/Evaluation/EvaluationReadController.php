<?php

namespace App\Seller\Evaluation;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class EvaluationReadController
{
    public function __invoke(Request $request, string $evaluation): mixed
    {
        $data = app(ProfileAccess::class)->run($request, function (User $actor, TenantContext $context) use ($evaluation): array {
            Gate::forUser($actor)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
            $row = DB::table('seller_evaluations')->where('organization_id', $context->organizationId)
                ->where('brand_id', $context->brandId)->where('id', $evaluation)->first() ?? abort(404);

            return ['id' => $row->id, 'sim_run_id' => $row->sim_run_id, 'conversation_id' => $row->conversation_id,
                'scores' => json_decode($row->scores, true), 'issues' => json_decode($row->issues, true),
                'average' => (float) $row->average, 'comment' => $row->comment,
                'test_baseline' => $row->sim_run_id !== null && $row->comment === ScenarioFallbackJudge::COMMENT];
        });

        if ($request->expectsJson()) {
            return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Seller/Quality/Evaluation', ['evaluation' => $data]);
    }
}
