<?php

namespace Tests\Feature\Shell\Team;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class TeamMemberAvatarE106JTest extends TenancyTestCase
{
    public function test_private_member_photo_requires_view_and_common_brand(): void
    {
        Storage::fake('local');
        [$owner, $org, $brand, $other] = $this->cabinet();
        [$target, $member] = $this->member($org, 'viewer', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$editor] = $this->member($org, 'member', [$brand->id]);
        [, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        [, $foreignMember] = $this->member($foreignOrg, 'viewer', [$foreignBrand->id]);
        $path = 'users/'.$target->id.'/profile/'.str_repeat('a', 32).'.png';
        Storage::disk('local')->put($path, 'private-image');
        DB::table('profile_preferences')->insert(['user_id' => $target->id, 'avatar_path' => $path, 'created_at' => now(), 'updated_at' => now()]);
        $url = '/team/'.$member->id.'/avatar';
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page->where('avatarUrl', $url));
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        self::assertSame('private-image', $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/team/'.$foreignMember->id.'/avatar')->assertNotFound();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get($url)->assertOk();
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id])->get($url)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get($url)->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        Storage::disk('local')->delete($path);
        $this->get($url)->assertNotFound();
        $this->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page->where('avatarUrl', null));
        $otherPath = 'users/'.$owner->id.'/profile/'.str_repeat('b', 32).'.png';
        Storage::disk('local')->put($otherPath, 'other-private-image');
        DB::table('profile_preferences')->where('user_id', $target->id)->update(['avatar_path' => $otherPath]);
        $this->get($url)->assertNotFound();
        $this->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page->where('avatarUrl', null));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_notification_link_opens_seller_settings_only_with_access(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [, $member] = $this->member($org, 'viewer', [$brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page->where('notificationSettingsUrl', '/seller/settings/notifications'));
        $this->get('/seller/settings/notifications')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Settings/Limits/Notifications', false));
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page->where('notificationSettingsUrl', '/seller/settings/notifications'));
        $this->get('/seller/settings/notifications')->assertOk();
        DB::table('brand_modules')->where('brand_id', $brand->id)->where('module', 'seller')->update(['enabled' => false]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/team/'.$member->id)->assertOk()->assertInertia(fn ($page) => $page->where('notificationSettingsUrl', null));
    }
}
