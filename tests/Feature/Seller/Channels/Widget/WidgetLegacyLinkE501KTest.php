<?php

namespace Tests\Feature\Seller\Channels\Widget;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetLegacyLinkE501KTest extends TenancyTestCase
{
    public function test_legacy_widget_is_linked_before_contact_and_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $widget = DB::table('widget_settings')->where('brand_id', $brand->id)->first();
        DB::table('widget_settings')->where('id', $widget->id)->update(['enabled' => true, 'origins' => json_encode(['https://example.test'])]);
        $this->get('/seller/channels')->assertOk();
        $channel = DB::table('channel_accounts')->where('brand_id', $brand->id)->where('type', 'widget')->sole();
        self::assertSame($widget->id, json_decode($channel->settings, true)['widget_id']);
        self::assertSame('active', $channel->status);
        $this->get('/seller/channels')->assertOk();
        $this->assertDatabaseCount('channel_accounts', 1);
        $input = ['enabled' => false, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Связаться', 'color' => '#225544', 'position' => 'right'], 'behavior' => ['invitation' => 'Здравствуйте!']];
        $this->post('/seller/channels/widget', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('channel_accounts', ['id' => $channel->id, 'status' => 'disabled']);
        $input['enabled'] = true;
        $this->post('/seller/channels/widget', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('channel_accounts', ['id' => $channel->id, 'status' => 'active']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/channels')->assertOk()->assertInertia(fn ($page) => $page->has('channels', 0));
    }
}
