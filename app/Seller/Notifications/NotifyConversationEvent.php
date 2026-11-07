<?php

namespace App\Seller\Notifications;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\TelegramSender;
use App\Shell\Notifications\Contracts\NotificationDispatcher;
use App\Shell\Notifications\DomainEvent;
use App\Shell\Notifications\EventCatalog;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotifyConversationEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $organizationId,
        public string $brandId,
        public string $conversationId,
        public string $eventKey,
        public array $data,
    ) {}

    public function handle(NotificationDispatcher $notifications): void
    {
        $minutes = (int) config('features.L1-06.defaults.dedup_minutes', 5);
        $claimed = DB::transaction(function () use ($notifications, $minutes): bool {
            if (! $this->claim($minutes)) {
                return false;
            }
            $notifications->enqueue(new DomainEvent([
                'event_id' => (string) Str::ulid(), 'schema_version' => 1, 'type' => $this->eventKey,
                'occurred_at' => now()->toIso8601String(), 'organization_id' => $this->organizationId,
                'brand_id' => $this->brandId, 'actor_id' => null,
                'aggregate' => ['type' => 'conversation', 'id' => $this->conversationId, 'version' => 1],
                'data' => $this->data,
            ]));

            return true;
        });
        if ($claimed) {
            $this->notifyManagersGroup();
        }
    }

    /** Not more than one notification per conversation and event type within the window. */
    private function claim(int $minutes): bool
    {
        $mark = DB::table('seller_notification_event_marks')
            ->where(['conversation_id' => $this->conversationId, 'event_key' => $this->eventKey])->lockForUpdate()->first();
        if ($mark && CarbonImmutable::parse($mark->notified_at)->addMinutes($minutes)->isFuture()) {
            return false;
        }
        if ($mark) {
            DB::table('seller_notification_event_marks')->where('id', $mark->id)->update(['notified_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('seller_notification_event_marks')->insert(['id' => (string) Str::ulid(), 'organization_id' => $this->organizationId,
                'brand_id' => $this->brandId, 'conversation_id' => $this->conversationId, 'event_key' => $this->eventKey,
                'notified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return true;
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
            app(TelegramSender::class)->managers($tenant, $account->id, EventCatalog::TITLES[$this->eventKey].': '.$link);
        });
    }
}
