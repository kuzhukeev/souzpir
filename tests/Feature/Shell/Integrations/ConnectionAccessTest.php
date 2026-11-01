<?php

namespace Tests\Feature\Shell\Integrations;

use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class ConnectionAccessTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_viewer_can_see_statuses_without_links_to_forbidden_settings(): void
    {
        Http::preventStrayRequests();
        [, $organization, $brand] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);

        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/integrations')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Integrations/Index')
            ->where('connections.2.id', 'api')
            ->where('connections.2.href', null)
            ->where('connections.2.settings_restricted', true)
            ->where('connections.3.id', 'notifications')
            ->where('connections.3.href', null)
            ->where('connections.3.settings_restricted', true));
        $this->get('/integrations/api')->assertForbidden();
        $this->get('/integrations/notifications')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_owner_and_admin_retain_settings_links_and_access(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $brand] = $this->cabinet();
        [$admin] = $this->member($organization, 'admin');

        foreach ([$owner, $admin] as $user) {
            $this->actingAs($user)->withSession(['brand_id' => $brand->id])->get('/integrations')
                ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('connections.2.href', route('integrations.api'))
                ->where('connections.2.settings_restricted', false)
                ->where('connections.3.href', route('integrations.notifications'))
                ->where('connections.3.settings_restricted', false));
            $this->get('/integrations/api')->assertOk();
            $this->get('/integrations/notifications')->assertOk();
        }
        Http::assertNothingSent();
    }
}
