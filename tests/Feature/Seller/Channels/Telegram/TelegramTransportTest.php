<?php

namespace Tests\Feature\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use App\Seller\Channels\Telegram\HttpTelegramTransport;
use App\Seller\Channels\Telegram\TelegramSender;
use App\Seller\Channels\Telegram\TelegramTransport;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramTransportTest extends LimitsTestCase
{
    public function test_fake_registration_and_send_with_brand_isolation(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
        config(['app.url' => 'https://example.com']);
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:ABCDE_abcde', 'managers_chat' => '-1001234', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $this->post('/seller/channels/telegram/register')->assertRedirect()->assertSessionHasNoErrors();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/setWebhook') && $request['url'] === 'https://example.com/webhooks/seller/telegram/'.$account->id && $request['secret_token'] === $account->credentials['webhook_secret']);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $sender = app(TelegramSender::class);
        $this->assertTrue($sender->send($tenant, $account->id, '1234', 'Ответ клиенту'));
        $this->assertTrue($sender->managers($tenant, $account->id, 'Сообщение менеджерам'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage') && $request['chat_id'] === '-1001234');
        $this->assertFalse($sender->send(new TenantContext($org->id, $other->id, source: 'job'), $account->id, '1234', 'Чужой бренд'));
        $this->assertFalse($sender->send($tenant, $account->id, 'bad', 'Текст'));
        $audit = DB::table('audit_logs')->where('action', 'seller.telegram.webhook.registration')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('123456:ABCDE_abcde', json_encode($audit));
        $this->assertStringNotContainsString($account->credentials['webhook_secret'], json_encode($audit));
    }

    public function test_failure_disabled_transport_permissions_and_invalid_url(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 403)]);
        [$owner, $org, $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:abcdef', 'mode' => 'bot'])->assertRedirect();
        config(['app.url' => 'http://localhost']);
        $this->post('/seller/channels/telegram/register')->assertSessionHasErrors('webhook');
        Http::assertNothingSent();
        config(['app.url' => 'https://example.com']);
        $this->post('/seller/channels/telegram/register')->assertSessionHasErrors('webhook');
        Http::assertNothingSent();
        app()->bind(TelegramTransport::class, HttpTelegramTransport::class);
        $this->post('/seller/channels/telegram/register')->assertSessionHasErrors('webhook');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/seller/channels/telegram/register')->assertForbidden();
    }
}
