<?php

namespace Tests\Feature\Seller\Channels\Widget;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetInstallationCheckTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function save(string $origin): void
    {
        $this->post('/seller/channels/widget', ['enabled' => true, 'origins' => [$origin],
            'appearance' => ['title' => 'Связаться', 'color' => '#176b42', 'position' => 'right'],
            'behavior' => ['delay_seconds' => null, 'scroll_percent' => null, 'invitation' => 'Здравствуйте']])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function key(string $brand): string
    {
        return DB::table('widget_settings')->where('brand_id', $brand)->value('public_key');
    }

    public function test_detects_installed_code_on_public_site(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->save('https://203.0.113.10');
        $key = $this->key($brand->id);
        Http::fake(['203.0.113.10*' => Http::response('<html><body><script src="/widget.js?key='.$key.'"></script></body></html>', 200)]);
        $this->post('/seller/channels/widget/check')->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'Код установлен'));
        Http::assertSentCount(1);
    }

    public function test_reports_code_not_found(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->save('https://203.0.113.10');
        Http::fake(['203.0.113.10*' => Http::response('<html><body>Нет кода</body></html>', 200)]);
        $this->post('/seller/channels/widget/check')->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'Код не найден'));
    }

    public function test_reports_unreachable_site_with_http_code(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->save('https://203.0.113.10');
        Http::fake(['203.0.113.10*' => Http::response('error', 500)]);
        $this->post('/seller/channels/widget/check')->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'HTTP 500'));
    }

    public function test_reports_timeout(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->save('https://203.0.113.10');
        Http::fake(['203.0.113.10*' => fn () => throw new ConnectionException('timed out')]);
        $this->post('/seller/channels/widget/check')->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'таймаут'));
    }

    public function test_rejects_private_address_before_request(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->save('https://169.254.169.254');
        Http::fake();
        $this->post('/seller/channels/widget/check')->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'запрещённый'));
        Http::assertNothingSent();
    }

    public function test_active_check_rate_limited_to_once_per_30_seconds(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->save('https://203.0.113.10');
        $key = $this->key($brand->id);
        Http::fake(['203.0.113.10*' => Http::response('key:'.$key, 200)]);
        $this->post('/seller/channels/widget/check')->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'Код установлен'));
        Http::assertSentCount(1);
        $this->post('/seller/channels/widget/check')->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'не чаще раза в 30 секунд'));
        Http::assertSentCount(1);
        $widgetId = DB::table('widget_settings')->where('brand_id', $brand->id)->value('id');
        RateLimiter::clear('widget-install-check:'.$widgetId);
        $this->post('/seller/channels/widget/check')->assertRedirect();
        Http::assertSentCount(2);
    }

    public function test_viewer_cannot_trigger_check(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->save('https://203.0.113.10');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/channels/widget/check')->assertForbidden();
    }
}
