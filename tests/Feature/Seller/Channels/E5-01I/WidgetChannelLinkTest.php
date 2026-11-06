<?php

namespace Tests\Feature\Seller\Channels\E501I;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetChannelLinkTest extends TenancyTestCase
{
    public function test_site_origins_are_scoped_and_widget_input_is_validated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $input = ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Связаться', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['delay_seconds' => 5, 'scroll_percent' => 50, 'invitation' => 'Здравствуйте!']];
        $this->post('/seller/channels/widget', $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/seller/channels')->assertOk()->assertInertia(fn ($page) => $page->where('siteOrigins.0', 'https://example.test')->where('siteEnabled', true));

        $widget = DB::table('widget_settings')->where('brand_id', $brand->id)->first();
        $channel = DB::table('channel_accounts')->where('brand_id', $brand->id)->where('type', 'widget')->first();
        self::assertSame($widget->id, json_decode($channel->settings, true)['widget_id']);
        self::assertSame('manager', $channel->default_mode);
        $date = now();
        DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'channel_account_id' => $channel->id, 'state' => '{}', 'last_client_message_at' => $date, 'created_at' => $date, 'updated_at' => $date]);
        $this->get('/seller/channels')->assertOk()->assertInertia(fn ($page) => $page->where('channels.0.id', $channel->id)->where('channels.0.mode', 'manager')->where('channels.0.dialogs7d', 1));
        $this->post('/seller/channels/widget', $input)->assertSessionHasNoErrors();
        self::assertSame(1, DB::table('channel_accounts')->where('brand_id', $brand->id)->where('type', 'widget')->count());

        $input['origins'] = ['http://invalid.test'];
        $this->post('/seller/channels/widget', $input)->assertSessionHasErrors('origins');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels')->assertOk()->assertInertia(fn ($page) => $page->where('siteOrigins', [])->where('siteEnabled', false)->has('channels', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/channels/widget', $input)->assertForbidden();
    }
}
