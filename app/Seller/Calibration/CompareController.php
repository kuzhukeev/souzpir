<?php

namespace App\Seller\Calibration;

use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class CompareController
{
    public function index(Request $request): mixed
    {
        $input = $request->validate(['before' => ['nullable', 'ulid'], 'after' => ['nullable', 'ulid']]);
        $data = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($input): array {
            Gate::forUser($user)->authorize('seller.knowledge.read');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $query = DB::table('seller_sim_runs as r')->join('seller_evaluations as e', function ($join): void {
                $join->on('e.sim_run_id', '=', 'r.id')->on('e.organization_id', '=', 'r.organization_id')->on('e.brand_id', '=', 'r.brand_id');
            })->join('seller_personas as p', function ($join): void {
                $join->on('p.id', '=', 'r.persona_id')->on('p.organization_id', '=', 'r.organization_id')->on('p.brand_id', '=', 'r.brand_id');
            })->where('r.organization_id', $tenant->organizationId)->where('r.brand_id', $tenant->brandId);
            $rows = (clone $query)->orderByDesc('r.created_at')->limit(100)->get(['r.id', 'r.persona_id', 'r.created_at', 'p.name', 'e.average', 'e.scores']);
            $runs = $rows->map(fn ($row) => ['id' => $row->id, 'persona_id' => $row->persona_id, 'name' => $row->name, 'created_at' => $row->created_at, 'average' => (float) $row->average]);
            $comparison = null;
            if (($input['before'] ?? null) && ($input['after'] ?? null)) {
                $before = (clone $query)->where('r.id', $input['before'])->first(['r.persona_id', 'e.scores']) ?? abort(404);
                $after = (clone $query)->where('r.id', $input['after'])->first(['r.persona_id', 'e.scores']) ?? abort(404);
                $a = json_decode($before->scores, true) ?: [];
                $b = json_decode($after->scores, true) ?: [];
                $items = [];
                foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $key) {
                    $old = $a[$key] ?? null;
                    $new = $b[$key] ?? null;
                    $delta = is_numeric($old) && is_numeric($new) ? round((float) $new - (float) $old, 2) : null;
                    $items[] = ['criterion' => $key, 'before' => $old, 'after' => $new, 'delta' => $delta];
                }
                $comparison = ['same_scenario' => $before->persona_id === $after->persona_id, 'items' => $items,
                    'improved' => count(array_filter($items, fn ($item) => $item['delta'] !== null && $item['delta'] > 0)),
                    'worsened' => count(array_filter($items, fn ($item) => $item['delta'] !== null && $item['delta'] < 0)),
                    'unchanged' => count(array_filter($items, fn ($item) => $item['delta'] !== null && $item['delta'] == 0)),
                    'total' => count($items)];
            }

            return ['runs' => $runs, 'comparison' => $comparison, 'filters' => $input];
        });

        return Inertia::render('Seller/Calibration/Compare', $data);
    }
}
