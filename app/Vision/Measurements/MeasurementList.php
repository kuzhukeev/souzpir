<?php

namespace App\Vision\Measurements;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Auth\SessionBrandSelection;
use App\Shell\Organizations\ModuleEntitlements;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
            $organization = DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->sharedLock()->first(['id']);
            $brand = DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->sharedLock()->first(['id']);
            $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $context->organizationId)
                ->where('user_id', $user->id)->where('status', 'active')->sharedLock()->first();
            abort_unless($organization && $brand && $member && $member->permitsBrand($context->brandId)
                && app(ModuleEntitlements::class)->enabled($context, 'vision') && Gate::forUser($user)->allows('vision.measure.read'), 403);

            return $callback($context, $revision);
        });
    }

    public function read(TenantContext $context, int $page): array
    {
        $query = DB::table('measurements as measurement')->join('question_sets as question_set', function ($join): void {
            $join->on('question_set.id', '=', 'measurement.question_set_id')
                ->on('question_set.organization_id', '=', 'measurement.organization_id')
                ->on('question_set.brand_id', '=', 'measurement.brand_id');
        })->where('measurement.organization_id', $context->organizationId)->where('measurement.brand_id', $context->brandId);
        $count = (clone $query)->count();
        $rows = $query->orderByDesc('measurement.created_at')->orderByDesc('measurement.id')->forPage($page, 25)
            ->get(['measurement.id', 'measurement.status', 'measurement.created_at', 'measurement.finished_at', 'question_set.size']);

        return ['data' => $rows->all(), 'page' => $page, 'last_page' => max(1, (int) ceil($count / 25)), 'total' => $count];
    }
}
