<?php

namespace App\Shell\MemberActivity;

use App\Models\Membership;
use App\Shell\Profile\ProfileAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class MemberLastLoginsController
{
    public function __invoke(Request $request, ProfileAccess $access): JsonResponse
    {
        $revision = $request->header('X-Brand-Revision');
        abort_unless(is_string($revision) && preg_match('/^[a-f0-9]{64}$/D', $revision) === 1, 409);
        $payload = $access->run($request, function ($user, $tenant) use ($request): array {
            Gate::forUser($user)->authorize('team.read');
            $input = Validator::make($request->query(), [
                'ids' => ['present', 'array', 'max:25'],
                'ids.*' => ['required', 'string', 'ulid', 'distinct'],
            ])->validate();
            $ids = $input['ids'];
            $members = Membership::query()->where('organization_id', $tenant->organizationId)
                ->whereIn('id', $ids)->sharedLock()->get(['id', 'user_id', 'organization_id']);
            abort_unless($members->count() === count($ids), 404);
            foreach ($members as $member) {
                Gate::forUser($user)->authorize('view', $member);
            }
            try {
                $times = DB::transaction(fn () => DB::table('member_last_logins')
                    ->whereIn('user_id', $members->pluck('user_id')->all())->pluck('last_login_at', 'user_id'));
            } catch (QueryException) {
                // A failed optional lookup must not disclose SQL or be mistaken for
                // a member who has never signed in. The savepoint keeps auth usable.
                return ['revision' => $request->attributes->get('profile.brand_revision'), 'available' => false, 'members' => []];
            }

            return [
                'revision' => $request->attributes->get('profile.brand_revision'),
                'available' => true,
                'members' => $members->map(static function (Membership $member) use ($times): array {
                    $time = $times->get($member->user_id);

                    return ['id' => $member->id, 'lastLoginAt' => $time === null ? null : CarbonImmutable::parse($time)->utc()->toISOString()];
                })->values()->all(),
            ];
        });

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }
}
