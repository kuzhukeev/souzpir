<?php

namespace Tests\Feature\Launch\L1_04;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\DisabledTelegramTransport;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramManagerMessageTransport;
use App\Seller\Channels\Telegram\TelegramTransport;
use App\Seller\Dialogs\DeliverManagerMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramLiveDeliveryTest extends LimitsTestCase
{
    public function test_feature_config_binds_live_transport_and_manager_delivery_from_env(): void
    {
        putenv('TELEGRAM_LIVE_ENABLED=true');
        putenv('TELEGRAM_MANAGER_DELIVERY_ENABLED=false');
        $config = require base_path('config/features/E5-01.php');
        self::assertSame(HttpTelegramTransport::class, $config['bindings'][TelegramTransport::class]);
        self::assertFalse($config['defaults']['manager_telegram_delivery']);
        putenv('TELEGRAM_MANAGER_DELIVERY_ENABLED');
        $config = require base_path('config/features/E5-01.php');
        self::assertTrue($config['defaults']['manager_telegram_delivery']);
        putenv('TELEGRAM_LIVE_ENABLED');
        $config = require base_path('config/features/E5-01.php');
        self::assertSame(DisabledTelegramTransport::class, $config['bindings'][TelegramTransport::class]);
        self::assertFalse($config['defaults']['manager_telegram_delivery']);
    }

    private function telegramConversation(string $org, string $brand, string $accountId): string
    {
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org, 'brand_id' => $brand,
            'channel_account_id' => $accountId, 'mode' => 'manager', 'state' => json_encode(['telegram_chat_id' => '1234']), 'created_at' => now(), 'updated_at' => now()]);

        return $conversation;
    }

    private function managerMessage(string $org, string $brand, string $conversation, string $text): string
    {
        $message = (string) Str::ulid();
        DB::table('messages')->insert(['id' => $message, 'organization_id' => $org, 'brand_id' => $brand,
            'conversation_id' => $conversation, 'role' => 'manager', 'text' => $text, 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        return $message;
    }

    public function test_retry_on_429_then_success_records_delivered_status_and_message_id(): void
    {
        Http::preventStrayRequests();
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        config(['features.E5-01.defaults.manager_telegram_delivery' => true]);
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $conversation = $this->telegramConversation($org->id, $brand->id, $account->id);
        $message = $this->managerMessage($org->id, $brand->id, $conversation, 'Ответ клиенту');

        Http::fakeSequence('api.telegram.org/*')
            ->push(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests'], 429)
            ->push(['ok' => true, 'result' => ['message_id' => 777]], 200);

        $job = new DeliverManagerMessage($org->id, $brand->id, $conversation, $message);
        try {
            $job->handle();
            self::fail('Expected a retryable exception on 429.');
        } catch (RuntimeException) {
            self::assertNull(DB::table('messages')->where('id', $message)->value('delivered_at'));
            self::assertNull(DB::table('messages')->where('id', $message)->value('delivery_error'));
        }
        $job->handle();
        Http::assertSentCount(2);
        self::assertNotNull(DB::table('messages')->where('id', $message)->value('delivered_at'));
        $meta = json_decode(DB::table('messages')->where('id', $message)->value('meta'), true);
        self::assertSame('live', $meta['delivery']);
        self::assertSame(777, $meta['telegram_message_id']);
    }

    public function test_4xx_failure_is_terminal_without_retry(): void
    {
        Http::preventStrayRequests();
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        config(['features.E5-01.defaults.manager_telegram_delivery' => true]);
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $conversation = $this->telegramConversation($org->id, $brand->id, $account->id);
        $message = $this->managerMessage($org->id, $brand->id, $conversation, 'Ответ клиенту');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);

        (new DeliverManagerMessage($org->id, $brand->id, $conversation, $message))->handle();
        Http::assertSentCount(1);
        self::assertNull(DB::table('messages')->where('id', $message)->value('delivered_at'));
        self::assertStringContainsString('chat not found', (string) DB::table('messages')->where('id', $message)->value('delivery_error'));
    }

    public function test_failed_hook_persists_error_once_retries_are_exhausted(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $conversation = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $conversation, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'mode' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        $message = $this->managerMessage($org->id, $brand->id, $conversation, 'Ответ клиенту');

        $job = new DeliverManagerMessage($org->id, $brand->id, $conversation, $message);
        self::assertSame(4, $job->tries);
        self::assertSame([10, 30, 120], $job->backoff());
        $job->failed(new RuntimeException('429: Too Many Requests'));
        self::assertSame('429: Too Many Requests', DB::table('messages')->where('id', $message)->value('delivery_error'));
        self::assertNull(DB::table('messages')->where('id', $message)->value('delivered_at'));
    }

    public function test_transport_does_not_resend_an_already_delivered_message(): void
    {
        Http::preventStrayRequests();
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        config(['features.E5-01.defaults.manager_telegram_delivery' => true]);
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $conversation = $this->telegramConversation($org->id, $brand->id, $account->id);
        $message = $this->managerMessage($org->id, $brand->id, $conversation, 'Ответ клиенту');
        DB::table('messages')->where('id', $message)->update(['delivered_at' => now()]);

        app(TelegramManagerMessageTransport::class)->send($org->id, $brand->id, $conversation, $message);
        Http::assertNothingSent();
    }

    public function test_webhook_registration_sends_secret_token_in_live_mode(): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://example.com']);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $this->post('/seller/channels/telegram/register')->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook')
            && $request['secret_token'] === $account->fresh()->credentials['webhook_secret']
            && $request['allowed_updates'] === ['message', 'my_chat_member']);
    }

    public function test_webhook_registration_shows_friendly_error_on_telegram_failure(): void
    {
        Http::preventStrayRequests();
        config(['app.url' => 'https://example.com']);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $this->post('/seller/channels/telegram/register')->assertSessionHasErrors('webhook');
    }

    public function test_dialog_message_flash_and_meta_reflect_live_mode(): void
    {
        Http::preventStrayRequests();
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $conversation = $this->telegramConversation($org->id, $brand->id, $account->id);
        DB::table('conversations')->where('id', $conversation)->update(['assigned_user_id' => $owner->id]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->post('/seller/dialogs/'.$conversation.'/messages', ['text' => 'Первый (fake)'])
            ->assertRedirect()->assertSessionHas('status', 'Сообщение поставлено в тестовую очередь.');
        $first = DB::table('messages')->where('conversation_id', $conversation)->where('text', 'Первый (fake)')->first();
        self::assertSame('fake', json_decode($first->meta, true)['delivery']);

        config(['features.E5-01.defaults.manager_telegram_delivery' => true]);
        $this->post('/seller/dialogs/'.$conversation.'/messages', ['text' => 'Второй (live)'])
            ->assertRedirect()->assertSessionHas('status', 'Сообщение поставлено в очередь отправки в Telegram.');
        $second = DB::table('messages')->where('conversation_id', $conversation)->where('text', 'Второй (live)')->first();
        self::assertSame('live', json_decode($second->meta, true)['delivery']);
    }
}
