<?php

namespace Tests\Feature\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramTransport;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramConnectionTest extends LimitsTestCase
{
    public function test_fake_verification_and_group_code_are_scoped(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/channels/telegram/verify', ['token' => 'bad'])->assertSessionHasErrors('token');
        $this->post('/seller/channels/telegram/verify', ['token' => '123:abc'])->assertSessionHas('telegram_verification.username', 'demo_123_bot');
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123:abc', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $this->post('/seller/channels/telegram/verify', ['token' => ''])->assertSessionHas('telegram_verification.username', 'demo_123_bot');
        $this->post('/seller/channels/telegram/link-code')->assertRedirect();
        $code = session('telegram_link_code');
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $url = '/webhooks/seller/telegram/'.$account->id;
        $payload = ['update_id' => 91, 'message' => ['message_id' => 1, 'chat' => ['id' => -100123, 'type' => 'supergroup'], 'text' => '/connect '.$code]];
        $this->postJson($url, $payload)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $account->credentials['webhook_secret'])->postJson($url, $payload)->assertOk();
        $this->assertSame('-100123', $account->fresh()->managers_chat_id);
        $this->assertArrayNotHasKey('link_code_hash', $account->fresh()->settings);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $other->id)->post('/seller/channels/telegram/link-code')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)->post('/seller/channels/telegram/verify', ['token' => '123:abc'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_webhook_status_tracks_fake_registration_and_token_change(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
        config(['app.url' => 'https://example.com']);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123:abc', 'mode' => 'bot'])->assertRedirect();
        $this->get('/seller/channels/telegram')->assertInertia(fn ($page) => $page->where('account.webhookInstalled', false));
        $this->post('/seller/channels/telegram/register')->assertSessionHasNoErrors();
        $this->get('/seller/channels/telegram')->assertInertia(fn ($page) => $page->where('account.webhookInstalled', true));
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '456:def', 'mode' => 'suggest'])->assertRedirect();
        $this->get('/seller/channels/telegram')->assertInertia(fn ($page) => $page->where('account.webhookInstalled', false)->where('account.mode', 'suggest'));
    }
}
