<?php

namespace Tests\Feature\Shell\InvitationIssuance;

use App\Models\Brand;
use App\Models\User;
use App\Shell\Audit\Contracts\AuditWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Shell\InvitationIssuance\Fixtures\IsolatedInvitationDatabase;
use Tests\Feature\Tenancy\TenancyTestCase;

final class InvitationIssuanceTest extends TenancyTestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        IsolatedInvitationDatabase::assert();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
    }

    private function payload(string $brand, string $attempt = ''): array
    {
        return [
            'attempt_key' => $attempt ?: bin2hex(random_bytes(32)), 'email' => 'invited@example.test',
            'role' => 'viewer', 'brand_ids' => [$brand], 'sales_manager' => false,
        ];
    }

    private function authorizeUser(User $user, string $brand): string
    {
        $this->actingAs($user)->withSession(['brand_id' => $brand]);
        $response = $this->get('/team')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->has('teamBrandRevision'));

        return $response->inertiaProps('teamBrandRevision');
    }

    public function test_real_login_creates_a_live_lease_for_issuance(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $this->postJson('/team/invitations', $this->payload($brand->id))->assertUnauthorized();
        $this->post('/login', ['email' => $owner->email, 'password' => 'test-only-password'])->assertRedirect(route('auth.ready'));
        self::assertSame(1, DB::table('auth_session_registry')->where('user_id', $owner->id)->whereNull('revoked_at')->count());
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $this->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/team')->assertOk()->inertiaProps('teamBrandRevision');
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($brand->id))->assertCreated();
        $this->post('/logout')->assertRedirect(route('login'));
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($brand->id))->assertUnauthorized();
    }

    public function test_invalid_issuance_key_closes_only_issuance(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $original = config('features.E1-06B.defaults.attempt_hmac_key');
        try {
            foreach ([null, '', 'not-base64', 'base64:%%%%', 'base64:'.base64_encode('too short')] as $invalid) {
                config(['features.E1-06B.defaults.attempt_hmac_key' => $invalid]);
                $response = $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($brand->id))->assertStatus(503);
                if (is_string($invalid) && $invalid !== '') {
                    $response->assertDontSee($invalid);
                }
            }
            $this->get('/team')->assertOk();
            self::assertSame(0, DB::table('invitations')->count());
            self::assertSame(0, DB::table('invitation_issuance_attempts')->count());
        } finally {
            config(['features.E1-06B.defaults.attempt_hmac_key' => $original]);
        }
    }

    public function test_app_key_rotation_does_not_permit_repeating_the_same_attempt(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $payload = $this->payload($brand->id);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $payload)->assertCreated();
        $original = config('app.key');
        try {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
            $newRevision = $this->authorizeUser($owner, $brand->id);
            $this->withHeader('X-Brand-Revision', $newRevision)->postJson('/team/invitations', $payload)->assertStatus(409);
            self::assertSame(1, DB::table('invitations')->count());
            self::assertSame(1, DB::table('invitation_issuance_attempts')->count());
        } finally {
            config(['app.key' => $original]);
        }
    }

    public function test_one_time_response_retry_and_existing_invitation_exchange(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        config(['app.url' => 'https://invitation.example.test']);
        $payload = $this->payload($brand->id);
        $response = $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $payload)->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $link = $response->json('link');
        self::assertMatchesRegularExpression('~^https://invitation\.example\.test/invitations/open#[A-Za-z0-9]{64}$~D', $link);
        $secret = substr($link, strrpos($link, '#') + 1);
        $row = DB::table('invitations')->where('organization_id', $org->id)->first();
        self::assertSame(hash('sha256', $secret), $row->token_hash);
        $receipt = DB::table('invitation_issuance_attempts')->first();
        self::assertSame($row->id, $receipt->invitation_id);
        self::assertSame($owner->id, $receipt->actor_user_id);
        self::assertNotSame($payload['attempt_key'], $receipt->attempt_hash);
        self::assertStringNotContainsString($secret, json_encode([$receipt, $row], JSON_THROW_ON_ERROR));
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $payload)->assertStatus(409)->assertDontSee($secret);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', [...$payload, 'email' => 'different@example.test'])->assertStatus(409)->assertDontSee($secret);
        self::assertSame(1, DB::table('invitations')->count());
        self::assertSame(1, DB::table('invitation_issuance_attempts')->count());
        $team = $this->get('/team')->assertOk();
        self::assertStringNotContainsString($secret, json_encode($team->inertiaProps(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($secret, json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR));
        $invitee = User::create(['name' => 'Invited', 'email' => $payload['email'], 'password' => 'test-only-password']);
        $invitee->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($invitee);
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));
        $this->postJson('/invitations/exchange', ['token' => $secret, 'epoch' => $match[1], 'sequence' => 1])->assertOk();
        $this->post('/invitations/accept')->assertRedirect(route('auth.ready'));
        $member = DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $invitee->id)->first();
        self::assertNotNull($member);
        self::assertSame('viewer', $member->role);
        self::assertSame([$brand->id], json_decode($member->brand_ids, true, flags: JSON_THROW_ON_ERROR));
        $this->postJson('/invitations/exchange', ['token' => $secret, 'epoch' => $match[1], 'sequence' => 2])->assertNotFound();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_expired_and_revoked_issued_invitations_cannot_be_exchanged(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $first = $this->payload($brand->id);
        $second = $this->payload($brand->id);
        $revokedLink = $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $first)->assertCreated()->json('link');
        $expiredLink = $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $second)->assertCreated()->json('link');
        $revokedToken = substr($revokedLink, strrpos($revokedLink, '#') + 1);
        $expiredToken = substr($expiredLink, strrpos($expiredLink, '#') + 1);
        self::assertNotSame($revokedToken, $expiredToken);
        $invitation = DB::table('invitations')->where('token_hash', hash('sha256', $revokedToken))->first();
        $this->withHeader('X-Brand-Revision', $revision)->post('/team/invitations/'.$invitation->id.'/revoke')->assertRedirect();
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $first)->assertStatus(409);
        $invitee = User::create(['name' => 'Invited', 'email' => $first['email'], 'password' => 'test-only-password']);
        $invitee->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($invitee);
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));
        $this->postJson('/invitations/exchange', ['token' => $revokedToken, 'epoch' => $match[1], 'sequence' => 1])->assertNotFound();
        $this->travel(7)->days();
        $this->actingAs($invitee);
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));
        $this->postJson('/invitations/exchange', ['token' => $expiredToken, 'epoch' => $match[1], 'sequence' => 1])->assertNotFound();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_retry_after_the_original_link_expires_still_returns_conflict(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $payload = $this->payload($brand->id);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $payload)->assertCreated();
        $this->travel(7)->days();
        $revision = $this->authorizeUser($owner, $brand->id);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $payload)->assertStatus(409);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', [...$payload, 'attempt_key' => bin2hex(random_bytes(32))])->assertCreated();
        self::assertSame(2, DB::table('invitations')->count());
    }

    public function test_server_sets_exactly_seven_days_and_auth_rejects_at_expiry(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-03-27 12:34:56', 'Europe/Berlin');
        $this->travelTo($issuedAt);
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $payload = $this->payload($brand->id);
        foreach (['2035-01-01T00:00:00+00:00', null, ''] as $untrustedExpiry) {
            $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', [...$payload, 'expires_at' => $untrustedExpiry])->assertStatus(422);
        }
        self::assertSame(0, DB::table('invitations')->count());
        $first = $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $payload)->assertCreated()->json('link');
        $second = $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($brand->id))->assertCreated()->json('link');
        $firstToken = substr($first, strrpos($first, '#') + 1);
        $secondToken = substr($second, strrpos($second, '#') + 1);
        $invitation = DB::table('invitations')->where('token_hash', hash('sha256', $firstToken))->first();
        self::assertSame($issuedAt->timestamp, CarbonImmutable::parse($invitation->created_at)->timestamp);
        self::assertSame($issuedAt->timestamp + 604800, CarbonImmutable::parse($invitation->expires_at)->timestamp);

        $invitee = User::create(['name' => 'Invited', 'email' => $payload['email'], 'password' => 'test-only-password']);
        $invitee->forceFill(['email_verified_at' => now()])->save();
        $this->travelTo($issuedAt->addSeconds(604799));
        $this->actingAs($invitee);
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));
        $this->postJson('/invitations/exchange', ['token' => $firstToken, 'epoch' => $match[1], 'sequence' => 1])->assertOk();
        $this->travelTo($issuedAt->addSeconds(604800));
        $page = $this->get('/invitations/open')->assertOk();
        self::assertSame(1, preg_match('/epoch:"([a-f0-9]{64})"/', $page->getContent(), $match));
        $this->postJson('/invitations/exchange', ['token' => $secondToken, 'epoch' => $match[1], 'sequence' => 1])->assertNotFound();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_failed_commit_neither_persists_nor_discloses_the_generated_secret(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $secret = str_repeat('Z', 64);
        Str::createRandomStringsUsing(fn () => $secret);
        $writer = \Mockery::mock(AuditWriter::class);
        $writer->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure: '.$secret));
        $this->app->instance(AuditWriter::class, $writer);
        config(['app.debug' => true]);
        DB::enableQueryLog();
        try {
            $response = $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($brand->id));
            $response->assertStatus(500)->assertDontSee($secret);
            self::assertStringNotContainsString($secret, (string) $response->exception);
            self::assertStringNotContainsString($secret, json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR));
            if (is_file(storage_path('logs/laravel.log'))) {
                self::assertStringNotContainsString($secret, file_get_contents(storage_path('logs/laravel.log')));
            }
            self::assertSame(0, DB::table('invitations')->count());
            self::assertSame(0, DB::table('invitation_issuance_attempts')->count());
        } finally {
            Str::createRandomStringsNormally();
            config(['app.debug' => false]);
            DB::disableQueryLog();
        }
    }

    public function test_permissions_brand_scope_and_active_status_are_enforced(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [$admin, $adminMembership] = $this->member($org, 'admin', [$first->id]);
        $revision = $this->authorizeUser($admin, $first->id);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($first->id))->assertCreated();
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($second->id))->assertNotFound();
        [, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($foreignBrand->id))->assertNotFound();
        DB::table('brands')->where('id', $first->id)->update(['status' => 'archived']);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($first->id))->assertClientError();
        DB::table('brands')->where('id', $first->id)->update(['status' => 'active']);
        DB::table('memberships')->where('id', $adminMembership->id)->update(['status' => 'disabled']);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($first->id))->assertClientError();
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        $viewerRevision = $this->authorizeUser($viewer, $first->id);
        $this->withHeader('X-Brand-Revision', $viewerRevision)->postJson('/team/invitations', $this->payload($first->id))->assertForbidden();
        self::assertSame(1, DB::table('invitations')->count());
    }

    public function test_no_unconfigured_brand_count_limit_is_imposed(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $ids = $this->within($org, null, function () use ($brand): array {
            $ids = [$brand->id];
            for ($index = 0; $index < 100; $index++) {
                $ids[] = Brand::create(['name' => 'Synthetic '.$index, 'theme' => 'neutral'])->id;
            }

            return $ids;
        });
        $revision = $this->authorizeUser($owner, $brand->id);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', [...$this->payload($brand->id), 'brand_ids' => $ids])->assertCreated();
        self::assertCount(101, json_decode(DB::table('invitations')->value('brand_ids'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_stale_brand_revision_revoked_session_bad_input_and_unsafe_transport_do_not_issue(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $payload = $this->payload($brand->id);
        $this->withHeader('X-Brand-Revision', str_repeat('0', 64))->postJson('/team/invitations', $payload)->assertStatus(409);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', [...$payload, 'expires_at' => now()->subSecond()->format('Y-m-d\TH:i:sP')])->assertStatus(422);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', [...$payload, 'role' => 'owner'])->assertStatus(422);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1', 'SERVER_ADDR' => '192.0.2.2'])
            ->withHeader('X-Brand-Revision', $revision)->postJson('http://request.example.test/team/invitations', $payload)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        DB::table('auth_session_registry')->where('user_id', $owner->id)->update(['revoked_at' => now()]);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $payload)->assertUnauthorized();
        self::assertSame(0, DB::table('invitations')->count());
        self::assertSame(0, DB::table('invitation_issuance_attempts')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_forwarded_protocol_spoof_and_insecure_production_origin_are_rejected(): void
    {
        [$owner, , $brand] = $this->cabinet();
        $revision = $this->authorizeUser($owner, $brand->id);
        $payload = $this->payload($brand->id);
        $this->withHeaders(['X-Brand-Revision' => $revision, 'X-Forwarded-Proto' => 'https'])
            ->postJson('/team/invitations', $payload)->assertForbidden();
        $this->flushHeaders();
        $originalEnvironment = config('app.env');
        $originalApplicationEnvironment = $this->app['env'];
        $originalUrl = config('app.url');
        try {
            config(['app.env' => 'production', 'app.url' => 'http://souzpir.local']);
            $this->app['env'] = 'production';
            $production = $this->withServerVariables(['HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1'])->withHeaders(['X-Brand-Revision' => $revision, 'X-CSRF-TOKEN' => csrf_token()])
                ->postJson('https://souzpir.local/team/invitations', $payload);
            self::assertSame(503, $production->getStatusCode(), $production->exception?->getMessage() ?? '');
            self::assertSame(0, DB::table('invitations')->count());
            config(['app.url' => 'https://example.test']);
            $link = $this->withHeader('X-Brand-Revision', $revision)->postJson('https://souzpir.local/team/invitations', $payload)->assertCreated()->json('link');
            self::assertStringStartsWith('https://example.test/invitations/open#', $link);
        } finally {
            config(['app.env' => $originalEnvironment, 'app.url' => $originalUrl]);
            $this->app['env'] = $originalApplicationEnvironment;
        }
    }
}
