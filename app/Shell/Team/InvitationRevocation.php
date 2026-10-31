<?php

namespace App\Shell\Team;

use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Team\Models\Invitation;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class InvitationRevocation
{
    public function revoke(Request $request, string $id): void
    {
        Gate::authorize('team.write');
        $context = app(TenantContext::class);
        app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $context, $id): void {
            $expected = $request->attributes->get('tenant.brand_revision');
            $selection = app(SessionBrandSelection::class)->current($request);
            abort_unless(is_string($expected) && (string) $selection->brand_revision === $expected
                && $selection->selected_organization_id === $context->organizationId
                && $selection->selected_brand_id === $context->brandId, 409);
            $provided = $request->header('X-Brand-Revision');
            $actual = app(SessionBrandSelection::class)->profileRevision($request, $expected);
            abort_unless(is_string($provided) && hash_equals($actual, $provided), 409);
            // Auth's exchange and acceptance lock this organization before the invitation.
            Organization::query()->whereKey($context->organizationId)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $actor = Membership::withoutGlobalScopes()->whereKey($context->membershipId)
                ->where('organization_id', $context->organizationId)->where('user_id', $user->id)
                ->where('status', 'active')->lockForUpdate()->first();
            abort_unless($actor && Gate::forUser($user)->allows('team.write') && $context->brandId && $actor->permitsBrand($context->brandId), 403);
            Brand::withoutGlobalScopes()->whereKey($context->brandId)->where('organization_id', $context->organizationId)
                ->where('status', 'active')->lockForUpdate()->firstOrFail();
            $invitation = Invitation::query()->whereKey($id)->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();

            $ids = $invitation->brand_ids;
            abort_unless(is_array($ids) && array_is_list($ids), 404);
            $unrestricted = in_array($actor->role, ['owner', 'admin'], true) && $actor->brand_ids === [];
            // An empty grant can mean all brands for an administrator; only unrestricted actors may revoke it.
            abort_if($ids === [] && ! $unrestricted, 404);
            foreach ($ids as $brandId) {
                abort_unless(is_string($brandId) && $actor->permitsBrand($brandId), 404);
            }
            $active = Brand::withoutGlobalScopes()->where('organization_id', $context->organizationId)
                ->where('status', 'active')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id'])->count();
            abort_unless($active === count($ids), 404);

            $invitation->revoked_at = now();
            $invitation->save();
            app(AuditWriter::class)->record($context, new AuditEntry('team.invitation.revoked', 'invitation', $invitation->id));
        });
    }
}
