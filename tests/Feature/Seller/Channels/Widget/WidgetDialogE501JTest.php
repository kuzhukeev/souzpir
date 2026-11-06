<?php

namespace Tests\Feature\Seller\Channels\Widget;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class WidgetDialogE501JTest extends TenancyTestCase
{
    public function test_legacy_widget_creates_brand_scoped_manager_dialog(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/channels/widget')->assertOk();
        $widget = DB::table('widget_settings')->where('brand_id', $brand->id)->first();
        DB::table('widget_settings')->where('id', $widget->id)->update(['enabled' => true, 'origins' => json_encode(['https://example.test']), 'behavior' => json_encode(['collect_contact' => true, 'consent_url' => 'https://example.test/privacy'])]);
        $url = '/widget/contact?key='.$widget->public_key;
        $this->withHeader('Origin', 'https://wrong.test')->postJson($url, ['consent' => true, 'email' => 'user@example.test'])->assertNotFound();
        $this->withHeader('Origin', 'https://example.test')->postJson($url, ['consent' => false, 'email' => 'user@example.test'])->assertUnprocessable();
        $this->postJson($url, ['consent' => true, 'email' => 'bad'])->assertUnprocessable();
        $this->postJson($url, ['consent' => true, 'email' => 'user@example.test', 'name' => 'Клиент'])->assertCreated();
        $channel = DB::table('channel_accounts')->where('brand_id', $brand->id)->where('type', 'widget')->sole();
        $dialog = DB::table('conversations')->where('channel_account_id', $channel->id)->sole();
        self::assertSame('manager', $dialog->mode);
        $this->assertDatabaseHas('contacts', ['id' => $dialog->contact_id, 'brand_id' => $brand->id, 'email' => 'user@example.test']);
        $this->assertDatabaseHas('messages', ['conversation_id' => $dialog->id, 'brand_id' => $brand->id, 'role' => 'client']);
        $this->assertDatabaseCount('conversations', 1);
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn ($page) => $page->has('dialogs', 1)->where('dialogs.0.id', $dialog->id));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/dialogs')->assertOk()->assertInertia(fn ($page) => $page->has('dialogs', 0));
    }
}
