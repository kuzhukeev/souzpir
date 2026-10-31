<?php

namespace App\Shell\Notifications\Http;

use App\Models\Membership;
use App\Shell\Auth\AuthSessionRegistry;
use App\Shell\Notifications\ConfigureNotification;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class NotificationSettingsController
{
    public function update(Request $request, ConfigureNotification $action, TenantContext $context): Response
    {
        $values = $request->validate(['event_key' => ['required', 'string', 'max:120'], 'channel' => ['required', 'string'], 'enabled' => ['required', 'boolean'], 'destination' => ['nullable', 'string', 'max:20']]);
        app(AuthSessionRegistry::class)->withinActive($request, function () use ($context, $values, $action): void {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->where('id', $context->membershipId)->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            abort_unless($member->permitsBrand($context->brandId), 403);
            $action->execute($context, $values['event_key'], $values['channel'], (bool) $values['enabled'], $values['destination'] ?? null);
        });

        return response()->noContent();
    }
}
