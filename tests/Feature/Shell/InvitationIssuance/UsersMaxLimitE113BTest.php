<?php

namespace Tests\Feature\Shell\InvitationIssuance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Shell\InvitationIssuance\Fixtures\IsolatedInvitationDatabase;
use Tests\Feature\Tenancy\TenancyTestCase;

final class UsersMaxLimitE113BTest extends TenancyTestCase
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

    private function limitUsers(string $org, string $value): void
    {
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'synthetic-'.$plan, 'name' => 'Synthetic', 'is_public' => false]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org, 'plan_id' => $plan, 'starts_at' => now()->subDay()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'users.max', 'value' => $value, 'unit' => 'шт.']);
    }

    private function payload(string $brand): array
    {
        return [
            'attempt_key' => bin2hex(random_bytes(32)), 'email' => 'invited@example.test',
            'role' => 'viewer', 'brand_ids' => [$brand], 'sales_manager' => false,
        ];
    }

    private function authorizeUser($user, string $brand): string
    {
        $this->actingAs($user)->withSession(['brand_id' => $brand]);
        $response = $this->get('/team')->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->has('teamBrandRevision'));

        return $response->inertiaProps('teamBrandRevision');
    }

    public function test_issuance_is_blocked_once_the_plan_user_limit_is_exhausted(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->limitUsers($org->id, '1');
        $revision = $this->authorizeUser($owner, $brand->id);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($brand->id))
            ->assertStatus(422)->assertJsonValidationErrors('users.max');
        self::assertSame(0, DB::table('invitations')->count());
    }

    public function test_issuance_succeeds_under_the_plan_user_limit(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $this->limitUsers($org->id, '2');
        $revision = $this->authorizeUser($owner, $brand->id);
        $this->withHeader('X-Brand-Revision', $revision)->postJson('/team/invitations', $this->payload($brand->id))->assertCreated();
        self::assertSame(1, DB::table('invitations')->count());
    }
}
