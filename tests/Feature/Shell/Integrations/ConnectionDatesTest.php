<?php

namespace Tests\Feature\Shell\Integrations;

use App\Seller\Channels\Core\ChannelAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class ConnectionDatesTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_active_channel_does_not_mistake_account_creation_for_connection_date(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $brand] = $this->cabinet();
        $account = $this->within($organization, $brand, fn () => ChannelAccount::create([
            'type' => 'telegram', 'name' => 'Synthetic channel', 'status' => 'disabled',
        ]));
        DB::table('channel_accounts')->where('id', $account->id)->update([
            'created_at' => '2020-01-02 10:00:00+00', 'updated_at' => '2020-01-02 10:00:00+00',
        ]);
        DB::table('channel_accounts')->where('id', $account->id)->update([
            'status' => 'active', 'updated_at' => '2026-10-31 12:00:00+00',
        ]);

        $response = $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/integrations');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Integrations/Index')
            ->where('connections.0.id', 'telegram')
            ->where('connections.0.status', 'active')
            ->where('connections.0.connected_at', null));
        self::assertStringNotContainsString('2020-01-02', $response->getContent());
        Http::assertNothingSent();
    }
}
