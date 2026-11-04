<?php

namespace Tests\Feature\Seller\Channels\Telegram;

use App\Seller\Channels\Core\ChannelAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class ChannelActivityTest extends LimitsTestCase
{
    public function test_activity_is_brand_scoped_and_reports_latest_client_message(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:abcdef', 'mode' => 'bot'])->assertRedirect();
        $account = ChannelAccount::withoutGlobalScopes()->where('brand_id', $brand->id)->firstOrFail();
        foreach ([now()->subDay(), now()->subDays(10)] as $date) {
            DB::table('conversations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'channel_account_id' => $account->id, 'state' => '{}', 'last_client_message_at' => $date, 'created_at' => $date, 'updated_at' => $date]);
        }
        $this->get('/seller/channels')->assertOk()->assertInertia(fn ($page) => $page->where('channels.0.dialogs7d', 1)->where('channels.0.lastActivity', fn ($date) => str_starts_with($date, now()->subDay()->toDateString())));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $other->id)->get('/seller/channels')->assertOk()->assertInertia(fn ($page) => $page->where('channels', []));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->withHeader('X-Brand-Id', $brand->id)->put('/seller/channels/telegram', ['name' => 'X', 'mode' => 'bot'])->assertForbidden();
        Http::assertNothingSent();
    }
}
