<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Shell\Audit\AuditEntry;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Shell\Notifications\RecipientPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class DigestPreferenceController
{
    public function __invoke(Request $request, TenantContext $context): RedirectResponse
    {
        Gate::authorize('profile.own');
        $data = $request->validate(['digest_time' => ['required', 'date_format:H:i']]);
        DB::transaction(function () use ($context, $data): void {
            DB::table('organizations')->where('id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            DB::table('brands')->where('id', $context->brandId)->where('organization_id', $context->organizationId)->where('status', 'active')->lockForUpdate()->first() ?? abort(404);
            $member = Membership::withoutGlobalScopes()->whereKey($context->membershipId)->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)->where('status', 'active')->lockForUpdate()->first() ?? abort(403);
            abort_unless($context->brandId && $member->permitsBrand($context->brandId), 403);
            abort_unless(collect(SellerNotificationRouter::EVENTS)->contains(fn ($event) => app(RecipientPolicy::class)->permits($member, $context->brandId, $event)), 403);
            $where = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $context->actorId];
            $old = DB::table('seller_digest_preferences')->where($where)->lockForUpdate()->first();
            DB::table('seller_digest_preferences')->updateOrInsert($where, ['id' => $old?->id ?? (string) Str::ulid(), 'digest_time' => $data['digest_time'], 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            app(AuditWriter::class)->record($context, new AuditEntry('seller.digest_preference.updated', 'user', $context->actorId, before: ['digest_time' => $old?->digest_time ?? '09:00'], after: ['digest_time' => $data['digest_time']]));
        });

        return redirect()->route('seller.settings.notifications.digests')->with('status', 'Время сводки сохранено.');
    }
}
