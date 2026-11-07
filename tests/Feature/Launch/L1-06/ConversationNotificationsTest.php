<?php

namespace Tests\Feature\Launch\L1_06;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramTransport;
use App\Seller\Conversations\Models\Message;
use App\Seller\Notifications\NotifyConversationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class ConversationNotificationsTest extends LimitsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['runtime.notification_driver' => 'fake', 'features.E0-04.defaults.external_delivery' => false, 'features.L1-06.defaults.enabled' => true]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function conversation(string $organizationId, string $brandId, ?string $channelAccountId = null): string
    {
        $id = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $id, 'organization_id' => $organizationId, 'brand_id' => $brandId,
            'channel_account_id' => $channelAccountId, 'mode' => 'manager', 'stage' => 'qualify', 'version' => 0,
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function drain(string $eventKey, string $organizationId, string $brandId, string $conversationId, array $data): void
    {
        (new NotifyConversationEvent($organizationId, $brandId, $conversationId, $eventKey, $data))->handle();
    }

    public function test_inbound_message_notifies_cabinet_and_managers_group_once_per_window(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$manager] = $this->member($org, 'member', [$brand->id]);
        [$foreignManager] = $this->member($org, 'member', [$other->id]);
        $account = $this->within($org, $brand, fn () => ChannelAccount::create(['type' => 'telegram', 'name' => 'Бот', 'status' => 'active',
            'managers_chat_id' => '-100555', 'credentials' => ['token' => '123456:ABCDE_abcde']]));
        $conversation = $this->conversation($org->id, $brand->id);

        $this->within($org, $brand, fn () => Message::create(['conversation_id' => $conversation, 'role' => 'client', 'text' => 'Сколько стоит доставка?']));
        Queue::assertPushed(NotifyConversationEvent::class, fn ($job): bool => $job->eventKey === 'seller.conversation.inbound' && $job->data['channel'] === 'unknown');
        $this->drain('seller.conversation.inbound', $org->id, $brand->id, $conversation, ['channel' => 'unknown']);

        $this->assertDatabaseHas('cabinet_notifications', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => 'seller.conversation.inbound', 'link' => '/seller/dialogs/'.$conversation]);
        $this->assertDatabaseHas('cabinet_notifications', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $manager->id, 'event_key' => 'seller.conversation.inbound']);
        $this->assertDatabaseMissing('cabinet_notifications', ['user_id' => $foreignManager->id, 'event_key' => 'seller.conversation.inbound']);
        Http::assertNothingSent();

        config(['features.E0-04.defaults.external_delivery' => true]);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        $this->within($org, $brand, fn () => Message::create(['conversation_id' => $conversation, 'role' => 'client', 'text' => 'Второй вопрос']));
        $this->drain('seller.conversation.inbound', $org->id, $brand->id, $conversation, ['channel' => 'unknown']);

        // Dedup window still open: no second group message and no second notification record.
        Http::assertNothingSent();
        $this->assertSame(1, DB::table('cabinet_notifications')->where('user_id', $owner->id)->where('event_key', 'seller.conversation.inbound')->count());

        CarbonImmutable::setTestNow(now()->addMinutes(10));
        try {
            $this->within($org, $brand, fn () => Message::create(['conversation_id' => $conversation, 'role' => 'client', 'text' => 'Третий вопрос']));
            $this->drain('seller.conversation.inbound', $org->id, $brand->id, $conversation, ['channel' => 'unknown']);
            Http::assertSentCount(1);
            Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '-100555' && str_contains($request['text'], '/seller/dialogs/'.$conversation));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_live_draft_ready_notifies_managers(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $conversation = $this->conversation($org->id, $brand->id);
        DB::table('conversations')->where('id', $conversation)->update(['mode' => 'suggest']);
        $turnId = (string) Str::ulid();

        $this->drain('seller.draft.ready', $org->id, $brand->id, $conversation, ['turn_id' => $turnId]);

        $this->assertDatabaseHas('cabinet_notifications', ['organization_id' => $org->id, 'brand_id' => $brand->id, 'user_id' => $owner->id, 'event_key' => 'seller.draft.ready']);
    }
}
