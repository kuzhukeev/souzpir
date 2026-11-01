<?php

namespace App\Shell\Billing\Http;

use App\Shell\Profile\ProfileAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AiRecoveryPageController
{
    public function __invoke(Request $request, ProfileAccess $access): Response
    {
        $access->run($request, function ($user, $tenant, $brand, $member, $organization): void {
            Gate::forUser($user)->authorize('billing.manage');
            abort_unless($tenant->source === 'session' && $member->role === 'owner'
                && $organization->owner_user_id === $user->id, 403);
        });

        return Inertia::render('Shell/Billing/AiRecovery', [
            'aiRecoveryRevision' => $request->attributes->get('profile.brand_revision'),
        ]);
    }
}
