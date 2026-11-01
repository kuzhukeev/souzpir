<?php

namespace Tests\Feature\Shell\Integrations;

use App\Seller\Channels\Core\ChannelAccount;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class IntegrationsPageTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_connection_statuses_follow_selected_brand_and_planned_options_are_honest(): void
    {
        [$owner, $organization, $first, $second] = $this->cabinet();
        $this->within($organization, $first, fn () => ChannelAccount::create(['type' => 'telegram', 'name' => 'First', 'status' => 'active']));
        $this->within($organization, $second, fn () => ChannelAccount::create(['type' => 'telegram', 'name' => 'Second', 'status' => 'error']));
        $this->actingAs($owner)->withSession(['brand_id' => $first->id])->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shell/Integrations/Index')->where('connections.0.status', 'active')->where('connections.4.status', 'soon'));
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect('/auth/ready');
        $this->get('/integrations')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('connections.0.status', 'error'));
        Http::assertNothingSent();
    }

    public function test_member_cannot_manage_keys_or_company_notification_settings(): void
    {
        [, $organization, $brand] = $this->cabinet();
        [$member] = $this->member($organization, 'member', [$brand->id]);
        $this->actingAs($member)->withSession(['brand_id' => $brand->id])->get('/integrations/api')->assertForbidden();
        $this->get('/integrations/notifications')->assertForbidden();
        Http::assertNothingSent();
    }
}
