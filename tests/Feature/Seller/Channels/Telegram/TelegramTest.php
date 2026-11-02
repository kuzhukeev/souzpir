<?php

namespace Tests\Feature\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramTest extends LimitsTestCase
{
    public function test_owner_configures_channel_receives_message_once_and_stops_bots(): void
    {
        Http::preventStrayRequests();
        [$owner, , $brand] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->get('/seller/channels')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Seller/Channels/Telegram/Index')->where('channels', []));
        $this->put('/seller/channels/telegram', ['name' => 'Наш бот', 'token' => '123456:ABCDE_abcde', 'managers_chat' => '-1001234', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $this->assertSame('Наш бот', $account->name);
        $this->assertSame('-1001234', $account->managers_chat_id);
        $this->assertSame('123456:ABCDE_abcde', $account->credentials['token']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'seller.telegram.saved')->where('subject_id', $account->id)->count());
        $payload = ['update_id' => 789, 'message' => ['message_id' => 11, 'chat' => ['id' => 1234, 'type' => 'private'], 'text' => 'Здравствуйте']];
        $url = '/webhooks/seller/telegram/'.$account->id;
        $this->postJson($url, $payload)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $account->credentials['webhook_secret'])->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(1, DB::table('channel_inbound_events')->where('channel_account_id', $account->id)->count());
        $this->assertSame(1, DB::table('messages')->where('channel_account_id', $account->id)->count());
        $this->get('/seller/channels')->assertInertia(fn (Assert $page) => $page->component('Seller/Channels/Telegram/Index')->where('channels.0.dialogs7d', 1));
        $this->post('/seller/channels/stop', ['confirm' => true])->assertRedirect();
        $this->assertSame('manager', $account->fresh()->default_mode);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'seller.channels.bots.stopped')->where('brand_id', $brand->id)->count());
        Http::assertNothingSent();
    }

    public function test_brand_and_role_boundaries_and_invalid_input(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->withHeader('X-Brand-Id', $first->id);
        $this->put('/seller/channels/telegram', ['name' => 'A', 'token' => 'bad', 'mode' => 'bot'])->assertSessionHasErrors('token');
        $this->post('/seller/channels/stop', [])->assertSessionHasErrors('confirm');
        $this->put('/seller/channels/telegram', ['name' => 'A', 'token' => '123456:abcdef', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $first->id)->firstOrFail();
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $second->id)->get('/seller/channels')->assertInertia(fn (Assert $page) => $page->where('channels', []));
        $this->patch('/seller/channels/'.$account->id.'/mode', ['mode' => 'manager'])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id])->withHeader('X-Brand-Id', $first->id);
        $this->put('/seller/channels/telegram', ['name' => 'X', 'mode' => 'bot'])->assertForbidden();
    }
}
