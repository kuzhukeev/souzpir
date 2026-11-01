<?php

namespace App\ProtectedOperations;

use App\Models\User;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Read-only projection; processing does not imply a stalled or absent effect. */
final class PendingSellerTurnsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($request): array {
            abort_unless(DB::connection()->getDriverName() === 'pgsql' && $tenant->source === 'session'
                && $tenant->actorId === $user->id && $user->hasVerifiedEmail(), 403);

            // Serialize entitlement checks with changes using the established lock order.
            $module = DB::table('brand_modules')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->where('module', 'seller')->lockForUpdate()->first();
            $assignments = DB::table('organization_plan')->where('organization_id', $tenant->organizationId)
                ->orderBy('id')->lockForUpdate()->get(['plan_id']);
            $planIds = $assignments->pluck('plan_id')->unique()->sort()->values()->all();
            if ($planIds !== []) {
                DB::table('plans')->whereIn('id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
                DB::table('plan_limits')->whereIn('plan_id', $planIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            abort_unless($module && $module->enabled && app(ModuleEntitlements::class)->enabled($tenant, 'seller')
                && Gate::forUser($user)->allows('seller.dialogues.operate'), 403);

            $input = $request->validate([
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'cursor' => ['sometimes', 'string', 'size:26'],
            ]);
            $cursor = $input['cursor'] ?? null;
            abort_if($cursor !== null && ! Str::isUlid($cursor), 422);
            $limit = (int) ($input['limit'] ?? 25);
            $query = DB::table('seller_turns')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->whereIn('status', ['uncertain', 'processing']);
            if ($cursor !== null) {
                $query->where('id', '<', $cursor);
            }
            $rows = $query->orderByDesc('id')->limit($limit + 1)->get(['id', 'conversation_id', 'status', 'created_at', 'updated_at']);
            $hasMore = $rows->count() > $limit;
            $items = $rows->take($limit)->map(static fn (object $row): array => [
                'conversation_id' => $row->conversation_id,
                'turn_id' => $row->id,
                'status' => $row->status,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ])->values()->all();

            return ['data' => $items, 'next_cursor' => $hasMore ? $items[count($items) - 1]['turn_id'] : null];
        });

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
