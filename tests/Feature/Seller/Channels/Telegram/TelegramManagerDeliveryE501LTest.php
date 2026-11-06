<?php

namespace Tests\Feature\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramManagerMessageTransport;
use App\Seller\Channels\Telegram\TelegramTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramManagerDeliveryE501LTest extends LimitsTestCase
{
    public function test_delivery_is_opt_in_scoped_and_only_sends_saved_manager_message(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $conversation = (string) Str::ulid();
        $message = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'channel_account_id' => $account->id, 'state' => json_encode(['telegram_chat_id' => '1234']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $conversation, 'role' => 'manager', 'text' => 'Ответ клиенту', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $transport = app(TelegramManagerMessageTransport::class);
        try {
            $transport->send($org->id, $brand->id, $conversation, $message);
            self::fail('Disabled delivery was accepted.');
        } catch (LogicException) {
            Http::assertNothingSent();
        }
        config(['features.E5-01.defaults.manager_telegram_delivery' => true]);
        try {
            $transport->send($org->id, $other->id, $conversation, $message);
            self::fail('Foreign brand was accepted.');
        } catch (LogicException) {
            Http::assertNothingSent();
        }
        $transport->send($org->id, $brand->id, $conversation, $message);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '1234' && $request['text'] === 'Ответ клиенту');
        $missing = (string) Str::ulid();
        try {
            $transport->send($org->id, $brand->id, $conversation, $missing);
            self::fail('Missing message was accepted.');
        } catch (LogicException) {
            Http::assertSentCount(1);
        }
    }
}
