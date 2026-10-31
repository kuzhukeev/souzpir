<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class TestDeliveryController
{
    public function __invoke(Request $request, TenantContext $context, TestDeliveryService $service): RedirectResponse
    {
        Gate::authorize('seller.settings.write');
        $data = $request->validate(['event' => ['required', Rule::in(SellerNotificationRouter::EVENTS)], 'channel' => ['required', Rule::in(['cabinet', 'email', 'telegram'])], 'key' => ['required', 'uuid']]);
        $id = app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $service, $data): string {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            abort_unless(in_array($member->role, ['owner', 'admin'], true) && $member->permitsBrand($context->brandId), 403);
            abort_unless(app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);

            return $service->request($context, $data['event'], $data['channel'], $data['key']);
        });
        TestDeliveryJob::dispatch($id)->afterCommit();

        return redirect()->route('seller.settings.notifications')->with('status', 'Тестовое уведомление поставлено в очередь.');
    }
}
