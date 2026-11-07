<?php

namespace Tests\Feature\Shell\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CompanyTelegramE110JTest extends TenancyTestCase
{
    public function test_expired_code_cannot_connect_a_chat(): void
    {
        config(['features.E1-08.defaults.telegram_webhook_secret' => str_repeat('s', 32), 'features.E1-08.defaults.telegram_bot_username' => 'example_bot']);
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/integrations/notifications/telegram/start')->assertRedirect();
        $code = $this->get('/integrations/notifications')->viewData('page')['props']['telegram_link']['code'];
        $this->travel(11)->minutes();
        $message = ['message' => ['text' => '/start '.$code, 'chat' => ['type' => 'private', 'id' => 123456], 'from' => ['id' => 123456, 'is_bot' => false]]];
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('s', 32))->postJson('/webhooks/company-telegram', $message)->assertNoContent();
        $this->post('/integrations/notifications/telegram/confirm')->assertSessionHasErrors('telegram');
        self::assertNull(DB::table('company_telegram_links')->value('pending_chat'));
    }

    public function test_code_requires_bot_confirmation_and_is_isolated_by_brand(): void
    {
        Http::preventStrayRequests();
        config(['features.E1-08.defaults.telegram_webhook_secret' => str_repeat('s', 32), 'features.E1-08.defaults.telegram_bot_username' => 'example_bot']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->post('/integrations/notifications/telegram/start')->assertRedirect();
        $code = $this->get('/integrations/notifications')->assertOk()->viewData('page')['props']['telegram_link']['code'];
        self::assertMatchesRegularExpression('/^EYON-[A-F0-9]{8}$/', $code);
        $this->post('/integrations/notifications/telegram/confirm')->assertSessionHasErrors('telegram');
        $message = ['message' => ['text' => '/start '.$code, 'chat' => ['type' => 'private', 'id' => 123456], 'from' => ['id' => 123456, 'is_bot' => false]]];
        $this->postJson('/webhooks/company-telegram', $message)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('s', 32))->postJson('/webhooks/company-telegram', $message)->assertNoContent();
        self::assertTrue($this->get('/integrations/notifications')->viewData('page')['props']['telegram_link']['pending']);
        $this->post('/integrations/notifications/telegram/confirm')->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('telegram_configured', true));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $other->id)->get('/integrations/notifications')->assertOk()->assertInertia(fn ($page) => $page->where('telegram_configured', false));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)->post('/integrations/notifications/telegram/start')->assertForbidden();
        self::assertSame(0, DB::table('company_telegram_links')->count());
        Http::assertNothingSent();
    }
}
