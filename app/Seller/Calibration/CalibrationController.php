<?php

namespace App\Seller\Calibration;

use App\Models\User;
use App\Seller\Evaluation\EvaluationVisibility;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

final class CalibrationController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.settings.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($user, $tenant);
        });
    }

    public function index(Request $request): mixed
    {
        $items = $this->access($request, function (User $user, TenantContext $tenant) {
            return DB::table('seller_evaluations as e')->leftJoin('seller_sim_runs as r', function ($join): void {
                $join->on('r.id', '=', 'e.sim_run_id')->on('r.organization_id', '=', 'e.organization_id')->on('r.brand_id', '=', 'e.brand_id');
            })
                ->leftJoin('seller_personas as p', 'p.id', '=', 'r.persona_id')
                ->leftJoin('judge_calibrations as c', function ($join) use ($user): void {
                    $join->on('c.evaluation_id', '=', 'e.id')->on('c.organization_id', '=', 'e.organization_id')->on('c.brand_id', '=', 'e.brand_id')->where('c.user_id', $user->id);
                })
                ->where(fn ($query) => EvaluationVisibility::excludeTestBaseline($query, 'e.'))->where('e.organization_id', $tenant->organizationId)->where('e.brand_id', $tenant->brandId)->where(fn ($q) => $q->whereNotNull('e.sim_run_id')->orWhereNotNull('e.conversation_id'))->orderByDesc('e.created_at')->limit(100)
                ->get(['e.id', 'e.average', 'e.created_at', 'r.id as run_id', 'e.conversation_id', 'p.name', 'c.rating', 'c.comment'])->map(fn ($row) => ['id' => $row->id, 'run_id' => $row->run_id, 'conversation_id' => $row->conversation_id, 'name' => $row->name ?? 'Диалог', 'judge' => (float) $row->average, 'rating' => $row->rating, 'comment' => $row->comment, 'created_at' => $row->created_at]);
        });

        return Inertia::render('Seller/Calibration/Index', ['items' => $items, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        if ($request->has('items')) {
            $input = $request->validate([
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.evaluation_id' => ['required', 'ulid', 'distinct'],
                'items.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
                'items.*.comment' => ['nullable', 'string', 'max:2000'],
            ]);
            $items = $input['items'];
        } else {
            $items = [$request->validate(['evaluation_id' => ['required', 'ulid'], 'rating' => ['required', 'integer', 'min:1', 'max:5'], 'comment' => ['nullable', 'string', 'max:2000']])];
        }
        $this->access($request, function (User $user, TenantContext $tenant) use ($items): void {
            DB::transaction(function () use ($items, $user, $tenant): void {
                foreach ($items as $item) {
                    DB::table('seller_evaluations')->where(fn ($query) => EvaluationVisibility::excludeTestBaseline($query))->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('id', $item['evaluation_id'])->where(fn ($q) => $q->whereNotNull('sim_run_id')->orWhereNotNull('conversation_id'))->first() ?? abort(404);
                    $where = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId, 'evaluation_id' => $item['evaluation_id'], 'user_id' => $user->id];
                    $old = DB::table('judge_calibrations')->where($where)->first();
                    $id = $old?->id ?? (string) Str::ulid();
                    DB::table('judge_calibrations')->updateOrInsert($where, ['id' => $id, 'rating' => $item['rating'], 'comment' => $item['comment'] ?? null, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
                    app(AuditWriter::class)->record($tenant, new AuditEntry('seller.judge.calibrated', 'judge_calibration', $id));
                }
            });
        });

        return back()->with('status', 'Оценки сохранены.');
    }
}
