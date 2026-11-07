<?php

namespace App\Seller\Notifications;

use App\Models\Membership;
use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\TelegramSender;
use App\Shell\Notifications\RecipientPolicy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotifyConversationEvent implements ShouldQueue
{
    use Queueable;

    /** Not registered in the shared docs/contracts/registry.json event catalog (owned by the integrator); see "Нужно от интегратора". */
    private const TITLES = [
        'seller.conversation.inbound' => 'Новое сообщение от клиента',
        'seller.draft.ready' => 'Черновик ответа готов',
    ];

    public function __construct(
        public string $organizationId,
        public string $brandId,
        public string $conversationId,
        public string $eventKey,
        public array $data,
    ) {}

    public function handle(): void
    {
        $minutes = (int) config('features.L1-06.defaults.dedup_minutes', 5);
        $notified = DB::transaction(fn () => $this->notifyCabinet($minutes));
        if ($notified) {
            $this->notifyManagersGroup();
        }
    }

    /** Cabinet notifications double as the record used for deduplication below. */
    private function notifyCabinet(int $minutes): bool
    {
        if ($this->recentlyNotified($minutes)
            || ! DB::table('organizations')->where('id', $this->organizationId)->where('status', 'active')->exists()
            || ! DB::table('brands')->where('id', $this->brandId)->where('organization_id', $this->organizationId)->where('status', 'active')->exists()) {
            return false;
        }
        $eventId = (string) Str::ulid();
        $envelope = ['event_id' => $eventId, 'schema_version' => 1, 'type' => $this->eventKey, 'occurred_at' => now()->toIso8601String(),
            'organization_id' => $this->organizationId, 'brand_id' => $this->brandId, 'actor_id' => null,
            'aggregate' => ['type' => 'conversation', 'id' => $this->conversationId, 'version' => 1], 'data' => $this->data];
        DB::table('outbox_events')->insert(['id' => $eventId, 'organization_id' => $this->organizationId, 'brand_id' => $this->brandId,
            'envelope' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'processed_at' => now()]);
        $title = self::TITLES[$this->eventKey];
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

        return true;
    }

    /** Not more than one notification per conversation and event type within the configured window. */
    private function recentlyNotified(int $minutes): bool
    {
        return DB::table('outbox_events')->where('organization_id', $this->organizationId)->where('brand_id', $this->brandId)
            ->where('created_at', '>', now()->subMinutes($minutes))
            ->whereRaw("envelope->>'type' = ?", [$this->eventKey])
            ->whereRaw("envelope->'aggregate'->>'id' = ?", [$this->conversationId])
            ->exists();
    }

    private function notifyManagersGroup(): void
    {
        if (! config('features.E0-04.defaults.external_delivery')) {
            return;
        }
        $tenant = new TenantContext($this->organizationId, $this->brandId, source: 'job');
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $account = ChannelAccount::query()->where('type', 'telegram')->where('status', 'active')->first();
            if (! $account || ! $account->managers_chat_id) {
                return;
            }
            $link = url('/seller/dialogs/'.$this->conversationId);
            app(TelegramSender::class)->managers($tenant, $account->id, self::TITLES[$this->eventKey].': '.$link);
        });
    }
}
