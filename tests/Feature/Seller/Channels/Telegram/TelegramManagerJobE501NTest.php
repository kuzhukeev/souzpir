<?php

namespace Tests\Feature\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramTransport;
use App\Seller\Dialogs\DeliverManagerMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramManagerJobE501NTest extends LimitsTestCase
{
    public function test_job_respects_flag_scopes_permissions_and_transport_failures(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        Queue::fake();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'channel_account_id' => $account->id, 'mode' => 'manager', 'assigned_user_id' => $owner->id,
            'state' => json_encode(['telegram_chat_id' => '1234']), 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/seller/dialogs/'.$conversation.'/messages', ['text' => 'Первый ответ'])->assertRedirect();
        $first = DB::table('messages')->where('conversation_id', $conversation)->where('text', 'Первый ответ')->first();
        Queue::assertPushed(DeliverManagerMessage::class, fn ($job) => $job->messageId === $first->id);
        (new DeliverManagerMessage($org->id, $brand->id, $conversation, $first->id))->handle();
        Http::assertNothingSent();
        self::assertNotNull(DB::table('messages')->where('id', $first->id)->value('delivered_at'));
        config(['features.E5-01.defaults.manager_telegram_delivery' => true]);
        $this->post('/seller/dialogs/'.$conversation.'/messages', ['text' => 'Второй ответ'])->assertRedirect();
        $second = DB::table('messages')->where('conversation_id', $conversation)->where('text', 'Второй ответ')->first();
        (new DeliverManagerMessage($org->id, $other->id, $conversation, $second->id))->handle();
        Http::assertNothingSent();
        (new DeliverManagerMessage($org->id, $brand->id, $conversation, $second->id))->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '1234' && $request['text'] === 'Второй ответ');
        self::assertNotNull(DB::table('messages')->where('id', $second->id)->value('delivered_at'));
        $this->post('/seller/dialogs/'.$conversation.'/messages', ['text' => 'Третий ответ'])->assertRedirect();
        $third = DB::table('messages')->where('conversation_id', $conversation)->where('text', 'Третий ответ')->first();
        DB::table('channel_accounts')->where('id', $account->id)->update(['status' => 'disabled']);
        try {
            (new DeliverManagerMessage($org->id, $brand->id, $conversation, $third->id))->handle();
            self::fail('Disabled account was accepted.');
        } catch (LogicException) {
            self::assertNull(DB::table('messages')->where('id', $third->id)->value('delivered_at'));
            Http::assertSentCount(1);
        }
        $otherConversation = (string) Str::ulid();
        $otherMessage = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $otherConversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'mode' => 'manager', 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $otherMessage, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $otherConversation, 'role' => 'manager', 'text' => 'Ответ в другом канале', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        (new DeliverManagerMessage($org->id, $brand->id, $otherConversation, $otherMessage))->handle();
        self::assertNotNull(DB::table('messages')->where('id', $otherMessage)->value('delivered_at'));
        Http::assertSentCount(1);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/dialogs/'.$conversation.'/messages', ['text' => 'Чужой ответ'])->assertForbidden();
    }
}
