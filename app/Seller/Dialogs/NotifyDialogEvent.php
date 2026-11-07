<?php

namespace App\Seller\Dialogs;

use App\Models\Membership;
use App\Shell\Notifications\EventCatalog;
use App\Shell\Notifications\RecipientPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Modeled on App\Seller\Notifications\NotifyConversationEvent: writes the outbox record and cabinet notifications directly, bypassing the generic queued dispatcher whose seller.contact.reminder gating belongs to the unrelated Planned/contact-reminder feature. */
final class NotifyDialogEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $organizationId,
        public string $brandId,
        public string $conversationId,
        public string $eventKey,
        public array $data,
    ) {}

    public function handle(): void
    {
        DB::transaction(function (): void {
            if (! DB::table('organizations')->where('id', $this->organizationId)->where('status', 'active')->exists()
                || ! DB::table('brands')->where('id', $this->brandId)->where('organization_id', $this->organizationId)->where('status', 'active')->exists()) {
                return;
            }
            $eventId = (string) Str::ulid();
            $envelope = ['event_id' => $eventId, 'schema_version' => 1, 'type' => $this->eventKey, 'occurred_at' => now()->toIso8601String(),
                'organization_id' => $this->organizationId, 'brand_id' => $this->brandId, 'actor_id' => null,
                'aggregate' => ['type' => 'conversation', 'id' => $this->conversationId, 'version' => 1], 'data' => $this->data];
            DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $this->organizationId, 'brand_id' => $this->brandId,
                'envelope' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'processed_at' => now()]);
            $title = EventCatalog::TITLES[$this->eventKey];
            $link = '/seller/dialogs/'.$this->conversationId;
            $policy = app(RecipientPolicy::class);
            $members = Membership::withoutGlobalScopes()->where('organization_id', $this->organizationId)->where('status', 'active')->get();
            foreach ($members as $member) {
                if (! $policy->permits($member, $this->brandId, $this->eventKey)) {
                    continue;
                }
                $deliveryId = (string) Str::ulid();
                DB::table('notification_deliveries')->insert(['id' => $deliveryId, 'organization_id' => $this->organizationId,
                    'brand_id' => $this->brandId, 'event_id' => $eventId, 'user_id' => $member->user_id, 'channel' => 'cabinet',
                    'status' => 'sent', 'attempts' => 0, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('cabinet_notifications')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->organizationId,
                    'brand_id' => $this->brandId, 'user_id' => $member->user_id, 'delivery_id' => $deliveryId, 'event_key' => $this->eventKey,
                    'title' => $title, 'link' => $link, 'created_at' => now()]);
            }
        });
    }
}
