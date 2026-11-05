<?php

namespace App\Seller\Followups;

use App\Seller\Runtime\Contracts\ChannelDelivery;
use App\Seller\Runtime\Contracts\DeliveryCommand;
use App\Seller\Settings\Limits\SellerModuleAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class FollowupSender
{
    public function send(TenantContext $tenant, string $candidateId, ChannelDelivery $delivery, FollowupPolicy $policy): bool
    {
        if (! $tenant->organizationId || ! $tenant->brandId || ! app(SellerModuleAccess::class)->enabled($tenant->organizationId, $tenant->brandId)) {
            return false;
        }
        try {
            return DB::transaction(function () use ($tenant, $candidateId, $delivery, $policy): bool {
                $scope = ['organization_id' => $tenant->organizationId, 'brand_id' => $tenant->brandId];
                $candidate = DB::table('seller_followup_candidates')->where($scope)->where('id', $candidateId)->first();
                if (! $candidate || $candidate->status !== 'pending') {
                    return false;
                }
                $brand = DB::table('brands')->where('organization_id', $tenant->organizationId)->where('id', $tenant->brandId)->where('status', 'active')->first(['timezone']);
                $conversation = DB::table('conversations')->where($scope)->where('id', $candidate->conversation_id)->first();
                if (! $brand || ! $conversation || $conversation->mode !== 'bot') {
                    return false;
                }
                $state = json_decode($conversation->state, true) ?: [];
                $settingsRow = DB::table('brand_seller_settings')->where($scope)->first(['followup']);
                $settings = $settingsRow ? (json_decode($settingsRow->followup, true) ?: []) : [];
                if (($settings['enabled'] ?? true) === false) {
                    return false;
                }
                $last = DB::table('messages')->where($scope)->where('conversation_id', $conversation->id)->where('role', 'agent')->orderByDesc('created_at')->orderByDesc('id')->value('created_at');
                $due = $policy->due(['stage' => $conversation->stage, 'state' => $state, 'do_not_disturb' => $conversation->do_not_disturb,
                    'followups_sent' => $conversation->followups_sent, 'last_client_message_at' => $conversation->last_client_message_at], $settings,
                    $last ? CarbonImmutable::parse($last) : null, CarbonImmutable::now(), $brand->timezone);
                if (! $due || $due['reason'] !== $candidate->reason || $due['sequence'] !== (int) $candidate->sequence
                    || ! CarbonImmutable::parse($due['source_at'])->equalTo(CarbonImmutable::parse($candidate->source_at))) {
                    return false;
                }
                $messageId = (string) Str::ulid();
                DB::table('messages')->insert(['id' => $messageId, ...$scope, 'conversation_id' => $conversation->id, 'channel_account_id' => $conversation->channel_account_id,
                    'role' => 'agent', 'text' => $candidate->text, 'meta' => json_encode(['goal' => ['kind' => 'followup_nudge'], 'reason' => $candidate->reason], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                $receipt = $delivery->send(new DeliveryCommand($tenant, $conversation->id, $messageId, 'fake', 'followup:'.$candidate->id));
                if ($receipt->status !== 'fake_sent' || $receipt->messageId !== $messageId) {
                    throw new RuntimeException('Fake delivery failed.');
                }
                $count = (int) $candidate->sequence;
                data_set($state, 'counters.followups_sent', $count);
                if ($candidate->reason === 'next_contact_at') {
                    data_set($state, 'flags._followup_done_'.CarbonImmutable::parse($candidate->source_at)->timestamp, true);
                }
                DB::table('conversations')->where($scope)->where('id', $conversation->id)->update(['state' => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'followups_sent' => $count, 'last_bot_message_at' => now(), 'updated_at' => now()]);
                DB::table('messages')->where($scope)->where('id', $messageId)->update(['delivered_at' => now(), 'updated_at' => now()]);
                DB::table('seller_followup_candidates')->where($scope)->where('id', $candidate->id)->update(['status' => 'completed', 'updated_at' => now()]);

                return true;
            });
        } catch (Throwable) {
            return false;
        }
    }
}
