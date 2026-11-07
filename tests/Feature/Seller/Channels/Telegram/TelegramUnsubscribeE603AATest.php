<?php

namespace Tests\Feature\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramUnsubscribeE603AATest extends LimitsTestCase
{
    public function test_bot_blocked_records_unsubscribe_once_and_blocks_sending(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'channel_account_id' => $account->id, 'state' => json_encode(['telegram_chat_id' => '555']), 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/seller/followups/consent/'.$conversation, ['event' => 'granted', 'basis' => 'Заявка'])->assertRedirect();

        $url = '/webhooks/seller/telegram/'.$account->id;
        $payload = ['update_id' => 1, 'my_chat_member' => ['chat' => ['id' => 555, 'type' => 'private'], 'new_chat_member' => ['status' => 'kicked']]];
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $account->credentials['webhook_secret'])->postJson($url, $payload)->assertOk();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $account->credentials['webhook_secret'])->postJson($url, $payload)->assertOk();

        $this->assertSame(1, DB::table('seller_followup_consents')->where('conversation_id', $conversation)->where('event', 'revoked')->where('source', 'dialog')->count());
        $this->assertSame(1, DB::table('channel_inbound_events')->where('channel_account_id', $account->id)->count());

        $candidate = (string) Str::ulid();
        DB::table('seller_followup_candidates')->insert(['id' => $candidate, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'conversation_id' => $conversation, 'reason' => 'silence', 'source_at' => now()->subDay(), 'sequence' => 1,
            'text' => 'Напоминание', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/seller/followups/schedule', ['candidates' => [$candidate], 'scheduled_at' => now()->addHour()->toIso8601String()])->assertStatus(422);
    }

    public function test_register_subscribes_to_chat_member_updates(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
        config(['app.url' => 'https://example.com']);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $this->post('/seller/channels/telegram/register')->assertRedirect()->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook') && $request['allowed_updates'] === ['message', 'my_chat_member']);
    }
}
