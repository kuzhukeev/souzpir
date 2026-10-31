<?php

namespace App\Seller\Settings\Limits;

use App\Models\Membership;
use App\Models\User;
use App\Shell\Notifications\RecipientPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class TestDeliveryService
{
    /** The caller must have locked and authorized the actor and tenant. */
    public function request(TenantContext $context, string $event, string $channel, string $key): string
    {
        $where = ['organization_id' => $context->organizationId, 'brand_id' => $context->brandId, 'user_id' => $context->actorId, 'request_key' => $key];
        $id = (string) Str::ulid();
        DB::table('seller_test_deliveries')->insertOrIgnore([...$where, 'id' => $id, 'event_key' => $event, 'channel' => $channel, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('seller_test_deliveries')->where($where)->lockForUpdate()->first();
        abort_unless($row && $row->event_key === $event && $row->channel === $channel, 409);

        return $row->id;
    }

    public function deliver(string $id): void
    {
        $snapshot = DB::table('seller_test_deliveries')->where('id', $id)->first();
        if (! $snapshot || $snapshot->status !== 'pending' || ($snapshot->channel !== 'cabinet' && ! config('features.E0-04.defaults.external_delivery', false))) {
            return;
        }
        if ($snapshot->channel !== 'cabinet' && DB::transactionLevel() !== 0) {
            throw new LogicException('External test delivery cannot run inside a transaction.');
        }
        $claimed = DB::transaction(function () use ($snapshot, $id): ?array {
            $user = User::whereKey($snapshot->user_id)->lock('for no key update')->first();
            $org = DB::table('organizations')->where('id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $brand = DB::table('brands')->where('id', $snapshot->brand_id)->where('organization_id', $snapshot->organization_id)->where('status', 'active')->lockForUpdate()->first();
            $member = Membership::withoutGlobalScopes()->where('organization_id', $snapshot->organization_id)->where('user_id', $snapshot->user_id)->where('status', 'active')->lockForUpdate()->first();
            $row = DB::table('seller_test_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return null;
            }
            $rule = DB::table('seller_notification_rules')->where(['organization_id' => $row->organization_id, 'brand_id' => $row->brand_id, 'event_key' => $row->event_key])->first();
            $personal = DB::table('notification_settings')->where(['organization_id' => $row->organization_id, 'brand_id' => $row->brand_id, 'user_id' => $row->user_id, 'event_key' => $row->event_key, 'channel' => $row->channel])->first();
            if (! $user || ! $org || ! $brand || ! $member || ! app(SellerModuleAccess::class)->enabled($row->organization_id, $row->brand_id) || ! in_array($row->event_key, SellerNotificationRouter::EVENTS, true)
                || ! app(RecipientPolicy::class)->permits($member, $row->brand_id, $row->event_key)
                || ($rule && (! $rule->{$row->channel} || ! app(SellerNotificationRouter::class)->matches($member, $rule->recipient)))
                || ($personal && ! $personal->enabled) || (! $personal && $row->channel !== 'cabinet')) {
                $this->update($id, 'cancelled', 'not_permitted');

                return null;
            }
            $title = 'Тест уведомлений продавца';
            if ($row->channel === 'cabinet') {
                DB::table('seller_test_cabinet_notifications')->insert(['delivery_id' => $id, 'organization_id' => $row->organization_id, 'brand_id' => $row->brand_id, 'user_id' => $row->user_id, 'title' => $title, 'created_at' => now()]);
                $this->update($id, 'sent', null, true);

                return null;
            }
            if ($row->channel === 'email') {
                if (! is_string($user->email) || $user->email === '' || in_array(config('mail.default'), ['log', 'array'], true)) {
                    $this->update($id, 'failed', 'transport_unavailable');

                    return null;
                }
                $destination = $user->email;
            } else {
                if (! $personal?->destination || ! is_string(config('features.E0-04.defaults.telegram_bot_token')) || config('features.E0-04.defaults.telegram_bot_token') === '') {
                    $this->update($id, 'failed', 'transport_unavailable');

                    return null;
                }
                try {
                    $destination = Crypt::decryptString($personal->destination);
                } catch (Throwable) {
                    $this->update($id, 'failed', 'invalid_destination');

                    return null;
                }
            }
            $this->update($id, 'sending', null);

            return ['channel' => $row->channel, 'destination' => $destination, 'titles' => [$title]];
        });
        if (! $claimed) {
            return;
        }
        try {
            app(DigestTransport::class)->send($claimed['channel'], $claimed['destination'], $claimed['titles']);
            DB::table('seller_test_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (Throwable) {
            DB::table('seller_test_deliveries')->where('id', $id)->where('status', 'sending')->update(['status' => 'uncertain', 'last_error' => 'delivery_uncertain', 'updated_at' => now()]);
        }
    }

    private function update(string $id, string $status, ?string $error, bool $sent = false): void
    {
        DB::table('seller_test_deliveries')->where('id', $id)->update(['status' => $status, 'last_error' => $error, 'sent_at' => $sent ? now() : null, 'updated_at' => now()]);
    }
}
