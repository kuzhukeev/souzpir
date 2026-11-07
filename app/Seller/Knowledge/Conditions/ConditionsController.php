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
use Illuminate\Validation\ValidationException;
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

            $factLinks = [];
            if ($draft) {
                $facts = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                    ->where('g.release_id', $draft->id)->where('f.status', 'active')->orderBy('g.position')->orderBy('f.position')
                    ->get(['f.id', 'f.text']);
                foreach ($facts as $fact) {
                    preg_match_all('/\{cond:([a-zA-Z0-9_.]+)\}/u', $fact->text, $matches);
                    foreach (array_unique($matches[1]) as $key) {
                        if (data_get($conditions, $key) !== null && data_get($conditions, $key) !== []) {
                            $factLinks[$key][] = ['id' => $fact->id, 'text' => $fact->text];
                        }
                    }
                }
            }

            return ['draft' => $draft, 'conditions' => $conditions, 'factLinks' => $factLinks];
        });

        return Inertia::render('Seller/Knowledge/Conditions/Index', [...$data, 'status' => session('status')]);
    }

    public function save(Request $request): mixed
    {
        $input = $request->validate(['version' => ['required', 'integer', 'min:1'], 'min_order_rub' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'offseason_min_order_rub' => ['nullable', 'numeric', 'min:0', 'max:999999999'], 'reserve_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'max_pct_unapproved' => ['required', 'integer', 'min:0', 'max:100'], 'new_client_bonus_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_order_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999'],
            'prepayment_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'availability' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'other_conditions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'deferred_payment' => ['sometimes', 'array'],
            'deferred_payment.new_clients' => ['nullable', 'string', 'max:255'],
            'deferred_payment.regular_clients' => ['nullable', 'string', 'max:255'],
            'discount_grid' => ['sometimes', 'array', 'max:50'], 'discount_grid.*.from' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'discount_grid.*.pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'payment_options' => ['sometimes', 'array', 'max:50'], 'payment_options.*.name' => ['required', 'string', 'max:255'],
            'payment_options.*.description' => ['nullable', 'string', 'max:2000'], 'payment_options.*.enabled' => ['required', 'boolean'],
            'delivery_methods' => ['sometimes', 'array', 'max:50'], 'delivery_methods.*.name' => ['required', 'string', 'max:255'],
            'delivery_methods.*.time' => ['nullable', 'string', 'max:255'], 'delivery_methods.*.cost' => ['nullable', 'string', 'max:255'],
            'delivery_methods.*.zones' => ['nullable', 'string', 'max:1000'],
            'promotions' => ['sometimes', 'array', 'max:50'], 'promotions.*.name' => ['required', 'string', 'max:255'],
            'promotions.*.text' => ['required', 'string', 'max:2000'], 'promotions.*.from' => ['nullable', 'date_format:Y-m-d'],
            'promotions.*.until' => ['required', 'date_format:Y-m-d'],
            'approved' => ['required', 'array'], 'approved.min_order_rub' => ['required', 'boolean'], 'approved.offseason_min_order_rub' => ['required', 'boolean'],
            'approved.reserve_days' => ['required', 'boolean'], 'approved.new_client_bonus_pct' => ['required', 'boolean']]);
        foreach ($input['promotions'] ?? [] as $index => $promotion) {
            if (! empty($promotion['from']) && $promotion['from'] > $promotion['until']) {
                throw ValidationException::withMessages(["promotions.$index.until" => 'Окончание акции не может быть раньше начала.']);
            }
        }
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
            foreach (['min_order_quantity', 'prepayment_pct', 'availability', 'other_conditions'] as $key) {
                if (array_key_exists($key, $input)) {
                    if ($input[$key] === null || $input[$key] === '') {
                        unset($conditions[$key]);
                    } else {
                        $conditions[$key] = in_array($key, ['min_order_quantity', 'prepayment_pct'], true) ? 0 + $input[$key] : $input[$key];
                    }
                }
            }
            if (array_key_exists('deferred_payment', $input)) {
                $deferred = $conditions['deferred_payment'] ?? [];
                foreach (['new_clients', 'regular_clients'] as $key) {
                    if (array_key_exists($key, $input['deferred_payment'])) {
                        $value = $input['deferred_payment'][$key];
                        if ($value === null || $value === '') {
                            unset($deferred[$key]);
                        } else {
                            $deferred[$key] = match ($value) {
                                'yes' => true, 'no' => false, default => $value
                            };
                        }
                    }
                }
                $conditions['deferred_payment'] = $deferred;
            }
            if (array_key_exists('discount_grid', $input)) {
                $conditions['discount_grid'] = array_map(fn (array $row): array => ['from' => 0 + $row['from'], 'pct' => 0 + $row['pct']], $input['discount_grid']);
            }
            if (array_key_exists('payment_options', $input)) {
                $conditions['payment_options'] = $input['payment_options'];
                $conditions['payment_methods'] = array_values(array_map(fn (array $option): string => $option['name'], array_filter($input['payment_options'], fn (array $option): bool => (bool) $option['enabled'])));
            }
            if (array_key_exists('delivery_methods', $input)) {
                $conditions['delivery_methods'] = $input['delivery_methods'];
            }
            if (array_key_exists('promotions', $input)) {
                $conditions['promotions'] = $input['promotions'];
            }
            $conditions['max_pct_unapproved'] = (int) $input['max_pct_unapproved'];
            $conditions['approvals'] = array_replace($conditions['approvals'] ?? [], $input['approved']);
            $data['conditions'] = $conditions;
            $repo->saveDraft($tenant, $draft->id, (int) $input['version'], $data);
        });

        return back()->with('status', 'Условия сохранены в черновике.');
    }
}
