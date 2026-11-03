<?php

namespace App\Seller\Knowledge\Conditions;

use App\Models\User;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class ConditionsController
{
    private function access(Request $request, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($callback) {
            Gate::forUser($user)->authorize('seller.knowledge.write');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return $callback($tenant);
        });
    }

    private function draft(TenantContext $tenant): ?object
    {
        return DB::table('knowledge_releases')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->where('status', 'draft')->first(['id', 'version']);
    }

    public function index(Request $request): mixed
    {
        $data = $this->access($request, function (TenantContext $tenant): array {
            $draft = $this->draft($tenant);
            $conditions = $draft ? app(KnowledgeRepository::class)->forRelease($tenant, $draft->id, true)->conditions() : null;

            return ['draft' => $draft, 'conditions' => $conditions];
        });

        return Inertia::render('Seller/Knowledge/Conditions/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'min_order_rub' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'offseason_min_order_rub' => ['nullable', 'numeric', 'min:0', 'max:999999999'], 'reserve_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'max_pct_unapproved' => ['required', 'integer', 'min:0', 'max:100'], 'new_client_bonus_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_grid' => ['sometimes', 'array', 'max:50'], 'discount_grid.*.from' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'discount_grid.*.pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'approved' => ['required', 'array'], 'approved.min_order_rub' => ['required', 'boolean'], 'approved.offseason_min_order_rub' => ['required', 'boolean'],
            'approved.reserve_days' => ['required', 'boolean'], 'approved.new_client_bonus_pct' => ['required', 'boolean']]);
        $this->access($request, function (TenantContext $tenant) use ($input): void {
            $draft = $this->draft($tenant) ?? abort(404, 'Создайте черновик базы знаний.');
            $repo = app(KnowledgeRepository::class);
            $data = $repo->forRelease($tenant, $draft->id, true)->toArray();
            $conditions = $data['conditions'];
            foreach (['min_order_rub', 'offseason_min_order_rub', 'reserve_days', 'new_client_bonus_pct'] as $key) {
                if (array_key_exists($key, $input) && $input[$key] !== null) {
                    $conditions[$key] = 0 + $input[$key];
                } else {
                    unset($conditions[$key]);
                }
            }
            if (array_key_exists('discount_grid', $input)) {
                $conditions['discount_grid'] = array_map(fn (array $row): array => ['from' => 0 + $row['from'], 'pct' => 0 + $row['pct']], $input['discount_grid']);
            }
            $conditions['max_pct_unapproved'] = (int) $input['max_pct_unapproved'];
            $conditions['approvals'] = array_replace($conditions['approvals'] ?? [], $input['approved']);
            $data['conditions'] = $conditions;
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Условия сохранены в черновике.');
    }
}
