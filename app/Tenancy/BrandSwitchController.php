<?php

namespace App\Tenancy;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\Models\Membership;
use App\ProtectedOperations\PostgresSessionIdempotency;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BrandSwitchController
{
    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate(['brand_id' => ['required', 'string', 'ulid']]);
        $key = $request->header('X-Context-Change-Key');
        abort_unless(is_string($key) && strlen($key) >= 16 && strlen($key) <= 128, 422);
        try {
            $receipt = app(PostgresSessionIdempotency::class)->recoverRevoked($request, 'profile.brand_switch', $key, $data['brand_id']);
        } catch (IdempotencyFailure) {
            abort(401);
        }

        return response()->json($receipt);
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['brand_id' => ['required', 'string', 'ulid']]);
        $context = app(TenantContext::class);
        $write = function () use ($request, $data, $context): array {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $brand = DB::table('brands')->where('id', $data['brand_id'])->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->where('id', $context->membershipId)->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            abort_unless($member->permitsBrand($brand->id), 404);
            $expected = $request->attributes->get('tenant.brand_revision');
            abort_unless(is_string($expected), 409);
            app(SessionBrandSelection::class)->select($request, $context->organizationId, $brand->id, $expected);
            $request->session()->put('brand_id', $brand->id);

            return ['switched' => true];
        };
        $key = $request->header('X-Context-Change-Key');
        if ($key !== null) {
            abort_unless(is_string($key) && strlen($key) >= 16 && strlen($key) <= 128, 422);
            try {
                app(PostgresSessionIdempotency::class)->execute($request, 'profile.brand_switch', $key, $data['brand_id'], $write);
            } catch (IdempotencyConflict) {
                abort(409);
            } catch (IdempotencyFailure) {
                try {
                    app(PostgresSessionIdempotency::class)->recoverRevoked($request, 'profile.brand_switch', $key, $data['brand_id']);
                } catch (IdempotencyFailure) {
                    abort(401);
                }

                return redirect()->route('login');
            }
        } else {
            app(AuthSessionRegistry::class)->withinActive($request, $write);
        }

        // Fixed same-origin destination; no user-supplied redirect URL.
        return redirect()->route($key !== null ? 'dashboard' : 'auth.ready');
    }
}
