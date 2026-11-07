<?php

namespace Tests\Feature\Seller\Channels\Widget;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetTest extends TenancyTestCase
{
    private function enable(string $org, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function input(array $origins = ['https://example.test']): array
    {
        return ['enabled' => true, 'origins' => $origins, 'appearance' => ['title' => 'Связаться', 'color' => '#225544', 'position' => 'right'],
            'behavior' => ['delay_seconds' => 5, 'scroll_percent' => 50, 'invitation' => 'Здравствуйте!']];
    }

    public function test_loader_and_domain_limited_public_config(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->enable($org->id, $other->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/widget')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Channels/Widget/Index')->where('settings.enabled', false));
        $key = DB::table('widget_settings')->where('brand_id', $brand->id)->value('public_key');
        $this->post('/seller/channels/widget', $this->input())->assertRedirect();
        $loader = $this->get('/widget.js?key='.$key.'&v=1')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        self::assertLessThanOrEqual(20480, strlen(gzencode($loader->getContent(), 9)));
        DB::table('brands')->where('id', $brand->id)->update(['timezone' => 'Asia/Vladivostok']);
        $this->withHeader('Origin', 'https://example.test')->getJson('/widget/config?key='.$key)->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://example.test')->assertJsonPath('appearance.title', 'Связаться')->assertJsonPath('timezone', 'Asia/Vladivostok');
        $this->assertDatabaseHas('widget_settings', ['brand_id' => $brand->id, 'enabled' => true]);
        self::assertNotNull(DB::table('widget_settings')->where('brand_id', $brand->id)->value('last_seen_at'));
        $this->withHeader('Origin', 'https://wrong.test')->getJson('/widget/config?key='.$key)->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels/widget')->assertOk()->assertInertia(fn ($page) => $page->where('settings.enabled', false)->has('settings.origins', 0));
        self::assertNotSame($key, DB::table('widget_settings')->where('brand_id', $other->id)->value('public_key'));
    }

    public function test_invalid_domains_and_viewer_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->enable($org->id, $brand->id);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/widget', $this->input(['http://example.test']))->assertSessionHasErrors('origins');
        $this->post('/seller/channels/widget', $this->input([]))->assertSessionHasErrors('origins');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/channels/widget')->assertForbidden();
        $this->post('/seller/channels/widget', $this->input())->assertForbidden();
    }
}
