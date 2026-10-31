<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Shell\Notifications\RecipientPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DigestInboxController
{
    public function __invoke(TenantContext $context): Response
    {
        Gate::authorize('profile.own');
        $member = Membership::withoutGlobalScopes()->where('id', $context->membershipId)->where('organization_id', $context->organizationId)->where('user_id', $context->actorId)->where('status', 'active')->first();
        abort_unless($member && $context->brandId && $member->permitsBrand($context->brandId), 403);
        abort_unless($context->organizationId && app(SellerModuleAccess::class)->enabled($context->organizationId, $context->brandId), 404);
        $items = DB::table('seller_digest_cabinet_notifications as n')->join('seller_digest_batches as b', 'b.id', '=', 'n.batch_id')
            ->where('n.organization_id', $context->organizationId)->where('n.brand_id', $context->brandId)->where('n.user_id', $context->actorId)
            ->where('b.organization_id', $context->organizationId)->where('b.brand_id', $context->brandId)->where('b.user_id', $context->actorId)->where('b.status', 'sent')
            ->orderByDesc('n.created_at')->select('n.batch_id', 'n.titles', 'n.event_keys', 'n.created_at')->limit(50)->get()
            ->map(function ($row) use ($member, $context): ?array {
                $titles = json_decode($row->titles, true, 512, JSON_THROW_ON_ERROR);
                $events = json_decode($row->event_keys, true, 512, JSON_THROW_ON_ERROR);
                $visible = [];
                foreach ($events as $index => $event) {
                    if (is_string($event) && isset($titles[$index]) && app(RecipientPolicy::class)->permits($member, $context->brandId, $event)) {
                        $visible[] = $titles[$index];
                    }
                }

                return $visible ? ['id' => $row->batch_id, 'titles' => $visible, 'created_at' => $row->created_at] : null;
            })->filter();

        $tests = DB::table('seller_test_cabinet_notifications as n')->join('seller_test_deliveries as d', 'd.id', '=', 'n.delivery_id')
            ->where('n.organization_id', $context->organizationId)->where('n.brand_id', $context->brandId)->where('n.user_id', $context->actorId)
            ->where('d.organization_id', $context->organizationId)->where('d.brand_id', $context->brandId)->where('d.user_id', $context->actorId)->where('d.status', 'sent')
            ->orderByDesc('n.created_at')->limit(50)->get(['n.delivery_id', 'n.title', 'n.created_at', 'd.event_key'])
            ->filter(fn ($row) => app(RecipientPolicy::class)->permits($member, $context->brandId, $row->event_key))
            ->map(fn ($row) => ['id' => $row->delivery_id, 'titles' => [$row->title], 'created_at' => $row->created_at]);
        $items = $items->concat($tests)->sortByDesc('created_at')->take(50)->values();

        $digestTime = DB::table('seller_digest_preferences')->where(['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $context->actorId])->value('digest_time') ?? '09:00';
        $timezone = DB::table('brands')->where('organization_id', $context->organizationId)->where('id', $context->brandId)->value('timezone');

        $canSetTime = collect(SellerNotificationRouter::EVENTS)->contains(fn ($event) => app(RecipientPolicy::class)->permits($member, $context->brandId, $event));

        return Inertia::render('Seller/Settings/Limits/DigestInbox', ['items' => $items, 'digestTime' => $digestTime, 'timezone' => $timezone, 'canSetTime' => $canSetTime, 'canReadSettings' => Gate::allows('seller.settings.read')]);
    }
}
