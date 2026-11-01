<?php

namespace App\Vision\Measurements;

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Tenancy\TenantContext;
use App\Vision\Site\SiteAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MeasurementList
{
    /** Hold the shared session lease across authorization, revision checks and the data read. */
    public function withinCurrent(#[\SensitiveParameter] Request $request, Closure $callback, bool $requireClientRevision = true): mixed
    {
        return app(AuthSessionRegistry::class)->withinActive($request, function (User $user) use ($request, $callback, $requireClientRevision): mixed {
            $context = app(TenantContext::class);
            $expected = $request->attributes->get('tenant.brand_revision');
            $selection = app(SessionBrandSelection::class)->current($request);
            abort_unless($context->source === 'session' && $context->actorId === $user->id
                && is_string($context->organizationId) && is_string($context->brandId) && is_string($context->membershipId)
                && is_string($expected) && (string) $selection->brand_revision === $expected
                && $selection->selected_organization_id === $context->organizationId
                && $selection->selected_brand_id === $context->brandId, 409);
            $revision = app(SessionBrandSelection::class)->profileRevision($request, $expected);
            if ($requireClientRevision) {
                $supplied = $request->header('X-Brand-Revision');
                abort_unless($request->header('X-Brand-Id') === $context->brandId && is_string($supplied)
                    && hash_equals($revision, $supplied), 409);
            }
            // The parent lock also prevents FK-backed insertion of a first plan assignment.
            abort_unless(DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first(['id']), 403);
            // Shared access acquires brand, membership, module, assignments, plans and limits
            // in the established Vision order before testing module entitlement and ability.
            app(SiteAccess::class)->lock($context, false, 'vision.measure.read');

            return $callback($context, $revision);
        });
    }

    public function read(TenantContext $context, int $page): array
    {
        $query = DB::table('measurements as measurement')
            ->where('measurement.organization_id', $context->organizationId)->where('measurement.brand_id', $context->brandId);
        $count = (clone $query)->count();
        $rows = $query->orderByDesc('measurement.created_at')->orderByDesc('measurement.id')->forPage($page, 25)
            ->get(['measurement.created_at']);

        return ['data' => $rows->all(), 'page' => $page, 'last_page' => max(1, (int) ceil($count / 25)), 'total' => $count];
    }
}
