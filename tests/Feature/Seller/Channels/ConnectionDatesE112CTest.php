<?php

namespace Tests\Feature\Seller\Channels;

use App\Seller\Channels\Core\ChannelAccount;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Seller\Settings\Limits\LimitsTestCase;

final class ConnectionDatesE112CTest extends LimitsTestCase
{
    public function test_actual_channel_activation_dates_are_scoped_and_preserved(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->withHeader('X-Brand-Id', $first->id);
        $this->put('/seller/channels/telegram', ['name' => 'Бот', 'token' => '123456:abcdef', 'mode' => 'bot'])->assertRedirect();
        $widget = ['enabled' => true, 'origins' => ['https://example.test'], 'appearance' => ['title' => 'Связаться', 'color' => '#225544', 'position' => 'right'],
            'behavior' => ['delay_seconds' => 5, 'scroll_percent' => 50, 'invitation' => 'Здравствуйте!']];
        $this->post('/seller/channels/widget', $widget)->assertRedirect();
        $telegram = ChannelAccount::withoutGlobalScopes()->where('brand_id', $first->id)->where('type', 'telegram')->firstOrFail();
        $channel = ChannelAccount::withoutGlobalScopes()->where('brand_id', $first->id)->where('type', 'widget')->firstOrFail();
        self::assertNotNull($telegram->connected_at);
        self::assertNotNull($channel->connected_at);
        $telegramDate = $telegram->connected_at->toISOString();
        $widgetDate = $channel->connected_at->toISOString();
        $this->travel(1)->seconds();
        $this->put('/seller/channels/telegram', ['name' => 'Бот 2', 'mode' => 'bot'])->assertRedirect();
        $this->post('/seller/channels/widget', $widget)->assertRedirect();
        self::assertSame($telegramDate, $telegram->fresh()->connected_at->toISOString());
        self::assertSame($widgetDate, $channel->fresh()->connected_at->toISOString());
        $this->get('/integrations')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.href', route('seller.channels.telegram'))
            ->where('connections.1.href', route('seller.widget.index'))
            ->where('connections.0.connected_at', fn ($value) => $value !== null)
            ->where('connections.1.connected_at', fn ($value) => $value !== null));
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->withHeader('X-Brand-Id', $second->id)->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.0.connected_at', null)->where('connections.1.connected_at', null));
        Http::assertNothingSent();
    }
}
