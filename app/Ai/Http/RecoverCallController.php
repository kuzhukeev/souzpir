<?php

namespace App\Ai\Http;

use App\Ai\Recovery\RecoverCall;
use App\Shell\Profile\ProfileAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class RecoverCallController
{
    public function __invoke(Request $request, string $callId, ProfileAccess $access, RecoverCall $recover): JsonResponse
    {
        $data = Validator::make(['call_id' => $callId], ['call_id' => ['required', 'ulid']])->validate();
        $result = $access->run($request, function ($user, $tenant, $brand, $member, $organization) use ($recover, $data): array {
            Gate::forUser($user)->authorize('billing.manage');
            abort_unless($tenant->source === 'session' && $member->role === 'owner'
                && $organization->owner_user_id === $user->id, 403);

            return $recover->run($tenant, $data['call_id']);
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
