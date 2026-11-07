<?php

namespace Tests\Browser\Acceptance;

use App\Seller\Channels\Core\ChannelAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class TelegramE901DTest extends LimitsTestCase
{
    public function test_inbound_telegram_can_be_taken_and_returned_without_external_calls(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Тестовый канал', 'token' => '123456:ABCDE_abcde', 'mode' => 'bot'])->assertRedirect()->assertSessionHasNoErrors();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        $payload = ['update_id' => 101, 'message' => ['message_id' => 55, 'chat' => ['id' => 1234, 'type' => 'private'], 'text' => 'Здравствуйте']];
        $url = '/webhooks/seller/telegram/'.$account->id;
        $this->postJson($url, $payload)->assertForbidden();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $account->credentials['webhook_secret'])->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $dialog = DB::table('conversations')->where('channel_account_id', $account->id)->first();
        self::assertNotNull($dialog);
        self::assertSame('bot', $dialog->mode);
        self::assertSame(1, DB::table('messages')->where('conversation_id', $dialog->id)->where('role', 'client')->count());
        self::assertSame(0, DB::table('messages')->where('conversation_id', $dialog->id)->where('role', 'agent')->count());
        $this->post('/seller/dialogs/'.$dialog->id.'/mode', ['action' => 'invalid'])->assertSessionHasErrors('action');
        $this->post('/seller/dialogs/'.$dialog->id.'/mode', ['action' => 'take'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $dialog->id, 'brand_id' => $brand->id, 'mode' => 'manager', 'assigned_user_id' => $owner->id]);
        $this->post('/seller/dialogs/'.$dialog->id.'/mode', ['action' => 'return'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('conversations', ['id' => $dialog->id, 'mode' => 'bot', 'assigned_user_id' => null]);
        $this->withSession(['brand_id' => $other->id])->withHeader('X-Brand-Id', $other->id)->post('/seller/dialogs/'.$dialog->id.'/mode', ['action' => 'take'])->assertStatus(409);
        $this->assertDatabaseHas('conversations', ['id' => $dialog->id, 'brand_id' => $brand->id, 'mode' => 'bot']);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)->post('/seller/dialogs/'.$dialog->id.'/mode', ['action' => 'take'])->assertForbidden();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
