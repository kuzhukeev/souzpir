<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Models\User;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Shell\Profile\ProfileAccess;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PendingPageController
{
    public function __invoke(Request $request): Response
    {
        return app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant, $brand, Membership $member): Response {
            abort_unless($user->hasVerifiedEmail() && $tenant->source === 'session', 403);
            Gate::forUser($user)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return Inertia::render('Seller/Dialogs/Pending', ['suggestions' => app(ReadySuggestions::class)->read($tenant, $user->id, $member)]);
        });
    }

    /** Polled by the queue page to refresh ready suggestions without a full Inertia reload. */
    public function json(Request $request): JsonResponse
    {
        $data = app(ProfileAccess::class)->run($request, function (User $user, TenantContext $tenant, $brand, Membership $member): array {
            abort_unless($user->hasVerifiedEmail() && $tenant->source === 'session', 403);
            Gate::forUser($user)->authorize('seller.dialogues.operate');
            abort_unless(app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId), 404);

            return app(ReadySuggestions::class)->read($tenant, $user->id, $member);
        });

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}
