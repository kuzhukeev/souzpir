<?php

namespace App\Seller\Channels\Planned;

use App\Models\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ContactReminderValidity
{
    public function valid(string $id, string $organizationId, string $brandId, string $userId): bool
    {
        $reminder = DB::table('seller_contact_reminder_events')->where([
            'id' => $id, 'organization_id' => $organizationId, 'brand_id' => $brandId, 'user_id' => $userId,
        ])->first();
        if (! $reminder || ! DB::table('seller_contact_reminder_settings')->where([
            'organization_id' => $organizationId, 'brand_id' => $brandId, 'enabled' => true,
        ])->exists()) {
            return false;
        }
        $brand = DB::table('brands')->where(['id' => $brandId, 'organization_id' => $organizationId, 'status' => 'active'])->first(['timezone']);
        $member = Membership::withoutGlobalScopes()->where(['organization_id' => $organizationId, 'user_id' => $userId, 'status' => 'active'])->first();
        $conversation = DB::table('conversations')->where([
            'id' => $reminder->conversation_id, 'organization_id' => $organizationId, 'brand_id' => $brandId, 'assigned_user_id' => $userId,
        ])->first(['state']);
        if (! $brand || ! $member || ! $member->permitsBrand($brandId) || ! $conversation
            || ! DB::table('organizations')->where(['id' => $organizationId, 'status' => 'active'])->exists()) {
            return false;
        }
        $state = json_decode($conversation->state, true);
        $due = app(ContactTime::class)->parse(data_get($state, 'slots.next_contact_at'), $brand->timezone);

        return $due !== null && $due->equalTo(CarbonImmutable::parse($reminder->due_at)) && $due->lessThanOrEqualTo(now());
    }
}
