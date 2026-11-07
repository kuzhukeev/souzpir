<?php

namespace Tests\Feature\Shell\Profile;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class LocalProfileTransportTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    public function test_local_shell_contains_no_personal_props_and_json_is_private_and_authorized(): void
    {
        [$user, $org, $brand] = $this->cabinet();
        $user->forceFill(['name' => 'Private Person Marker', 'phone' => '+79991234567'])->save();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->app['env'] = 'local';
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        foreach (['profile', 'notifications', 'security'] as $screen) {
            $path = $screen === 'profile' ? '/profile' : '/profile/'.$screen;
            $response = $this->get('http://souzpir.local'.$path);
            $response->assertOk();
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $page = $response->viewData('page');
            self::assertSame('Shell/Profile/Local', $page['component']);
            self::assertSame($screen, $page['props']['screen']);
            self::assertEmpty($page['encryptHistory'] ?? false);
            $serialized = json_encode($page, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($user->email, $serialized);
            self::assertStringNotContainsString('Private Person Marker', $serialized);
            self::assertStringNotContainsString('+79991234567', $serialized);
            self::assertStringNotContainsString($org->name, $serialized);
            self::assertArrayNotHasKey('notificationSettings', $page['props']);
            self::assertArrayNotHasKey('security', $page['props']);
            $json = $this->getJson('http://souzpir.local/profile/local-data/'.$screen);
            $json->assertOk();
            self::assertStringContainsString('no-store', $json->headers->get('Cache-Control'));
            if ($screen === 'profile') $json->assertJsonPath('profile.email', $user->email);
            if ($screen === 'security') $json->assertJsonPath('security.setup', null);
        }
    }

    public function test_local_json_rejects_proxy_remote_production_and_revoked_membership(): void
    {
        [$user, , $brand, , $member] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->app['env'] = 'local';
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1', 'SERVER_ADDR' => '127.0.0.1']);
        $this->getJson('http://souzpir.local/profile/local-data/profile')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        $this->withHeader('X-Forwarded-For', '127.0.0.1')->getJson('http://souzpir.local/profile/local-data/profile')->assertNotFound();
        $this->flushHeaders();
        $this->app['env'] = 'production';
        $this->getJson('http://souzpir.local/profile/local-data/profile')->assertNotFound();
        $this->app['env'] = 'local';
        $this->getJson('http://other.local/profile/local-data/profile')->assertNotFound();
        DB::table('memberships')->where('id', $member->id)->update(['status' => 'disabled']);
        $this->getJson('http://souzpir.local/profile/local-data/profile')->assertForbidden();
    }
}
