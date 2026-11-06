<?php

namespace App\Seller\Reviews;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Conversations\Data\ReviewType;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class ReviewsController
{
    private function access(Request $request, string $permission, callable $callback): mixed
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant, $brand, Membership $member) use ($permission, $callback) {
            Gate::forUser($user)->authorize($permission);
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);
            $manager = $member->role === 'member' && ($member->settings['sales_manager'] ?? false);

            return $callback($user, $tenant, $manager);
        });
    }

    private function rows(TenantContext $tenant, User $user, bool $manager): mixed
    {
        return DB::table('message_reviews as r')
            ->join('messages as m', function ($join) {
                $join->on('m.id', '=', 'r.message_id')->on('m.organization_id', '=', 'r.organization_id')->on('m.brand_id', '=', 'r.brand_id');
            })
            ->join('conversations as c', function ($join) {
                $join->on('c.id', '=', 'm.conversation_id')->on('c.organization_id', '=', 'r.organization_id')->on('c.brand_id', '=', 'r.brand_id');
            })
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.organization_id', $tenant->organizationId)->where('r.brand_id', $tenant->brandId)
            ->when($manager, fn ($query) => $query->where('c.assigned_user_id', $user->id));
    }

    public function index(Request $request): mixed
    {
        $filter = $request->validate(['type' => ['nullable', Rule::enum(ReviewType::class)], 'status' => ['nullable', Rule::in(['new', 'done', 'dismissed'])], 'period' => ['nullable', Rule::in(['7', '30', '90'])], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $this->access($request, 'seller.dialogs.read', fn () => null);

        return Inertia::render('Seller/Reviews/Index', [
            'reviews' => Inertia::defer(function () use ($request, $filter): array {
                try {
                    return $this->access($request, 'seller.dialogs.read', function (User $user, TenantContext $tenant, bool $manager) use ($filter): array {
                        $base = $this->rows($tenant, $user, $manager)->when($filter['period'] ?? null, fn ($query, $days) => $query->where('r.created_at', '>=', now()->subDays((int) $days)));
                        $counts = (clone $base)->selectRaw('r.type, count(*) as total')->groupBy('r.type')->pluck('total', 'type');
                        $items = $base->when($filter['type'] ?? null, fn ($query, $value) => $query->where('r.type', $value))
                            ->when($filter['status'] ?? null, fn ($query, $value) => $query->where('r.status', $value))
                            ->orderByDesc('r.created_at')->orderByDesc('r.id')->paginate(25, ['r.id', 'r.type', 'r.status', 'r.note', 'r.applied_to', 'r.created_at', 'm.text', 'c.id as conversation_id', 'u.name as author'], 'page', (int) ($filter['page'] ?? 1));

                        return ['items' => $items->items(), 'pagination' => ['current' => $items->currentPage(), 'last' => $items->lastPage(), 'total' => $items->total()], 'counts' => $counts, 'filters' => $filter, 'canOperate' => Gate::forUser($user)->allows('seller.dialogues.operate')];
                    });
                } catch (QueryException $exception) {
                    report($exception);

                    return ['items' => [], 'pagination' => ['current' => 1, 'last' => 1, 'total' => 0], 'counts' => [], 'filters' => $filter, 'canOperate' => false, 'loadError' => true];
                }
            }),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function dismiss(Request $request): mixed
    {
        $input = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'ulid', 'distinct']]);
        $this->access($request, 'seller.dialogues.operate', function (User $user, TenantContext $tenant, bool $manager) use ($input): void {
            $ids = $this->rows($tenant, $user, $manager)->whereIn('r.id', $input['ids'])->where('r.status', 'new')->pluck('r.id');
            abort_unless($ids->count() === count($input['ids']), 404);
            DB::table('message_reviews')->where('organization_id', $tenant->organizationId)->where('brand_id', $tenant->brandId)->whereIn('id', $ids)->where('status', 'new')->update(['status' => 'dismissed', 'updated_at' => now()]);
        });

        return back()->with('status', 'Пометки отклонены.');
    }
}
