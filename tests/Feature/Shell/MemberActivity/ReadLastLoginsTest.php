<?php

namespace Tests\Feature\Shell\MemberActivity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;
use Tests\Support\MfaTestDatabase;

final class ReadLastLoginsTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        MfaTestDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function url(array $ids): string
    {
        return '/team/member-last-logins?'.http_build_query(['ids' => $ids]);
    }

    public function test_team_read_returns_only_requested_members_of_the_current_organization(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [$target, $targetMembership] = $this->member($organization, 'viewer', [$brand->id]);
        [, $unknownMembership] = $this->member($organization, 'member', [$brand->id]);
        [, $foreignOrganization, $foreignBrand] = $this->cabinet(false);
        [, $foreignMembership] = $this->member($foreignOrganization, 'viewer', [$foreignBrand->id]);
        DB::table('member_last_logins')->insert(['user_id' => $target->id, 'last_login_at' => '2026-10-31 20:00:00+00']);
        $this->getJson($this->url([$targetMembership->id]))->assertUnauthorized();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/team')->assertOk()->inertiaProps('teamBrandRevision');
        $response = $this->getJson($this->url([$targetMembership->id, $unknownMembership->id]), ['X-Brand-Revision' => $revision])
            ->assertOk()->assertJsonPath('revision', $revision)->assertJsonPath('available', true)->assertJsonCount(2, 'members');
        $rows = collect($response->json('members'))->keyBy('id');
        $this->assertSame('2026-10-31T20:00:00.000000Z', $rows[$targetMembership->id]['lastLoginAt']);
        $this->assertNull($rows[$unknownMembership->id]['lastLoginAt']);
        $this->assertSame(['id', 'lastLoginAt'], array_keys($rows[$targetMembership->id]));
        $this->assertStringNotContainsString($target->id, $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson($this->url([$targetMembership->id]), ['X-Brand-Revision' => $revision, 'X-Brand-Id' => $brand->id])->assertOk();
        $this->getJson($this->url([$targetMembership->id]), ['X-Brand-Revision' => $revision, 'X-Brand-Id' => $foreignBrand->id])->assertNotFound();
        $this->getJson($this->url([$targetMembership->id, $foreignMembership->id]), ['X-Brand-Revision' => $revision])->assertNotFound();
        $this->getJson($this->url([$foreignMembership->id]), ['X-Brand-Revision' => $revision])->assertNotFound();
        $this->getJson($this->url([$targetMembership->id, $targetMembership->id]), ['X-Brand-Revision' => $revision])->assertUnprocessable();
        $this->getJson($this->url(['invalid']), ['X-Brand-Revision' => $revision])->assertUnprocessable();
        $this->getJson($this->url(array_fill(0, 26, (string) Str::ulid())), ['X-Brand-Revision' => $revision])->assertUnprocessable();
        $this->getJson($this->url([$targetMembership->id]))->assertStatus(409);
        $owner->forceFill(['auth_version' => $owner->auth_version + 1])->save();
        $this->getJson($this->url([$targetMembership->id]), ['X-Brand-Revision' => $revision])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_viewer_can_read_the_same_team_but_editor_cannot_and_stale_brand_revision_fails(): void
    {
        [, $organization, $brand, $otherBrand] = $this->cabinet();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id, $otherBrand->id]);
        [$editor] = $this->member($organization, 'member', [$brand->id]);
        [, $target] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/team')->assertOk()->inertiaProps('teamBrandRevision');
        $this->getJson($this->url([$target->id]), ['X-Brand-Revision' => $revision])->assertOk()->assertJsonPath('members.0.lastLoginAt', null);
        $this->post('/brand/switch', ['brand_id' => $otherBrand->id])->assertRedirect();
        $this->getJson($this->url([$target->id]), ['X-Brand-Revision' => $revision])->assertStatus(409);
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id]);
        $editorRevision = $this->get('/profile')->assertOk()->inertiaProps('profileBrandRevision');
        $this->getJson($this->url([$target->id]), ['X-Brand-Revision' => $editorRevision])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unmigrated_schema_is_unavailable_not_fabricated_and_login_still_works(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [, $target] = $this->member($organization, 'viewer', [$brand->id]);
        Schema::drop('member_last_logins');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/team')->assertOk()->inertiaProps('teamBrandRevision');
        $this->getJson($this->url([$target->id]), ['X-Brand-Revision' => $revision])->assertOk()
            ->assertExactJson(['revision' => $revision, 'available' => false, 'members' => []]);
        $this->assertAuthenticatedAs($owner);
        $this->assertSame(1, DB::selectOne('select 1 as ok')->ok);
        Http::assertNothingSent();
    }

    public function test_database_lookup_error_does_not_expose_sql_or_invalidate_the_session(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [$user, $target] = $this->member($organization, 'viewer', [$brand->id]);
        DB::table('member_last_logins')->insert(['user_id' => $user->id, 'last_login_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/team')->assertOk()->inertiaProps('teamBrandRevision');
        DB::statement('ALTER TABLE member_last_logins RENAME TO member_last_logins_backup');
        DB::statement('CREATE VIEW member_last_logins AS SELECT user_id, (1 / 0)::text AS last_login_at FROM member_last_logins_backup');

        $response = $this->getJson($this->url([$target->id]), ['X-Brand-Revision' => $revision])->assertOk()
            ->assertExactJson(['revision' => $revision, 'available' => false, 'members' => []]);
        $this->assertStringNotContainsString('division by zero', $response->getContent());
        $this->assertAuthenticatedAs($owner);
        $this->assertSame(1, DB::selectOne('select 1 as ok')->ok);
        Http::assertNothingSent();
    }
}
