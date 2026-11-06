<?php

namespace Tests\Feature\Seller\Dashboard;

use App\Seller\Channels\Core\ChannelAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ChannelDisabledAtE509CTest extends TenancyTestCase
{
    public function test_confirmed_disconnection_age_and_legacy_channels_are_brand_scoped(): void
    {
        CarbonImmutable::setTestNow('2026-11-05 12:00:00 UTC');
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $channel = $this->within($org, $brand, fn () => ChannelAccount::create(['type' => 'widget', 'name' => 'Сайт', 'status' => 'active', 'settings' => []]));
        $this->within($org, $brand, function () use ($channel): void {
            $channel->status = 'disabled';
            $channel->save();
        });
        self::assertTrue($channel->fresh()->disabled_at->equalTo(now()));
        $legacy = (string) Str::ulid();
        DB::table('channel_accounts')->insert(['id' => $legacy, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'type' => 'api', 'status' => 'disabled', 'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
        $this->within($org, $brand, function () use ($legacy): void {
            $old = ChannelAccount::findOrFail($legacy);
            $old->name = 'Изменённое название';
            $old->save();
            self::assertNull($old->disabled_at);
        });
        CarbonImmutable::setTestNow('2026-11-06 12:00:00 UTC');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->has('dashboard.attention', 1)->where('dashboard.attention.0.kind', 'channels')->where('dashboard.attention.0.count', 2));
        CarbonImmutable::setTestNow('2026-11-06 12:00:01 UTC');
        $this->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->has('dashboard.attention', 2)->where('dashboard.attention.0.title', 'Канал выключен >24ч')->where('dashboard.attention.0.count', 1)->where('dashboard.attention.1.detail', '1 · Время отключения неизвестно'));
        $this->within($org, $brand, function () use ($channel): void {
            $channel->status = 'active';
            $channel->save();
        });
        self::assertNull($channel->fresh()->disabled_at);
        $this->within($org, $brand, function () use ($channel): void {
            $channel->status = 'disabled';
            $channel->save();
        });
        self::assertTrue($channel->fresh()->disabled_at->equalTo(now()));
        $this->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->has('dashboard.attention', 1)->where('dashboard.attention.0.count', 2));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/attention')->assertOk()->assertInertia(fn ($page) => $page->has('dashboard.attention', 0));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/attention')->assertOk();
        $this->get('/seller/attention?days=0')->assertSessionHasErrors('days');
        CarbonImmutable::setTestNow();
    }
}
