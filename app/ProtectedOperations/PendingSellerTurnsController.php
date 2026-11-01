<?php

namespace App\ProtectedOperations;

use App\Models\User;
use App\Shell\Organizations\ModuleEntitlements;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Read-only projection; processing does not imply a stalled or absent effect. */
final class PendingSellerTurnsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $this->authorized($request, function (TenantContext $tenant) use ($request): array {
            $input = $request->validate([
                'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
                'cursor' => ['sometimes', 'string', 'size:26'],
                'status' => ['sometimes', 'string', Rule::in(['uncertain', 'processing'])],
            ]);
            $cursor = $input['cursor'] ?? null;
            abort_if($cursor !== null && ! Str::isUlid($cursor), 422);
            $limit = (int) ($input['limit'] ?? 25);
            $query = DB::table('seller_turns')->where('organization_id', $tenant->organizationId)
                ->where('brand_id', $tenant->brandId)->whereIn('status', isset($input['status']) ? [$input['status']] : ['uncertain', 'processing']);
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

    public function show(Request $request, string $turn): JsonResponse
    {
        try {
            $data = $this->authorized($request, function (TenantContext $tenant) use ($turn): array {
                $query = DB::table('seller_turns as t')->where('t.organization_id', $tenant->organizationId)
                    ->where('t.brand_id', $tenant->brandId)->where('t.id', $turn)->whereIn('t.status', ['uncertain', 'processing']);
                $columns = ['t.id', 't.conversation_id', 't.status', 't.created_at', 't.updated_at'];
                foreach (['extractor', 'responder', 'guard'] as $name) {
                    $query->leftJoin('c02_'.$name.'_stages as '.$name, function ($join) use ($name): void {
                        $join->on($name.'.turn_id', '=', 't.id')->on($name.'.conversation_id', '=', 't.conversation_id')
                            ->on($name.'.organization_id', '=', 't.organization_id')->on($name.'.brand_id', '=', 't.brand_id');
                    })->leftJoin('ai_calls as '.$name.'_call', function ($join) use ($name): void {
                        $join->on($name.'_call.id', '=', $name.'.ai_call_id')->on($name.'_call.organization_id', '=', 't.organization_id')
                            ->on($name.'_call.brand_id', '=', 't.brand_id')->on($name.'_call.object_id', '=', 't.conversation_id')
                            ->where($name.'_call.object_type', '=', 'conversation')->where($name.'_call.family', '=', 'seller')
                            ->where($name.'_call.purpose', '=', $name);
                    });
                    array_push($columns, $name.'.id as '.$name.'_stage', $name.'.ai_call_id as '.$name.'_bound_call', $name.'_call.status as '.$name.'_call_status');
                }
                $row = $query->first($columns) ?? abort(404);
                $stages = [];
                foreach (['extractor', 'responder', 'guard'] as $name) {
                    if ($row->{$name.'_stage'} !== null) {
                        $callStatus = $row->{$name.'_call_status'};
                        abort_if($row->{$name.'_bound_call'} !== null && $callStatus === null, 503);
                        $stages[$name] = ['ai_call_status' => $callStatus];
                    }
                }

                return ['conversation_id' => $row->conversation_id, 'turn_id' => $row->id, 'status' => $row->status,
                    'created_at' => $row->created_at, 'updated_at' => $row->updated_at, 'stages' => (object) $stages,
                    'other_effects_may_exist' => true];
            });
        } catch (QueryException $error) {
            // A deployment may not yet have the C-02 stage tables. Avoid leaking
            // SQL or claiming that missing storage means an absent effect.
            if ($error->getCode() !== '42P01') {
                throw $error;
            }

            return response()->json(['message' => 'Подробности временно недоступны.'], 503)
                ->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    private function authorized(Request $request, Closure $action): array
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant) use ($action): array {
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

            return $action($tenant);
        });
    }
}
