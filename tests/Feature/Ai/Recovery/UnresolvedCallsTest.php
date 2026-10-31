<?php

namespace Tests\Feature\Ai\Recovery;

use App\Models\Brand;
use App\Models\Organization;
use App\Shell\Auth\SessionBrandSelection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class UnresolvedCallsTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function budget(Organization $organization, Brand $brand): string
    {
        $id = (string) Str::ulid();
        DB::table('ai_budgets')->insert(['id' => $id, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'family' => 'seller', 'period' => '2026-10-01', 'spent_micros' => 4, 'reserved_micros' => 100,
            'unresolved_count' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function seedCall(Organization $organization, Brand $brand, string $budget, string $status): string
    {
        $id = (string) Str::ulid();
        DB::table('ai_calls')->insert(['id' => $id, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'budget_id' => $budget, 'idempotency_key_hash' => hash('sha256', $id), 'request_hash' => hash('sha256', 'private-request-'.$id),
            'family' => 'seller', 'purpose' => 'PRIVATE_PURPOSE', 'provider' => 'PRIVATE_PROVIDER',
            'requested_model' => 'PRIVATE_REQUESTED_MODEL', 'model' => 'PRIVATE_MODEL', 'object_type' => 'brand', 'object_id' => $brand->id,
            'data_classes' => '["knowledge_public"]', 'attempt' => 1, 'status' => $status,
            'reserved_micros' => 100, 'error_code' => 'PRIVATE_ERROR', 'result_encrypted' => 'PRIVATE_RESULT',
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_owner_sees_only_unresolved_calls_in_the_selected_brand_without_private_fields_or_mutation(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        [, $other, $foreign] = $this->cabinet(false);
        $budget = $this->budget($org, $first);
        $secondBudget = $this->budget($org, $second);
        $foreignBudget = $this->budget($other, $foreign);
        $uncertain = $this->seedCall($org, $first, $budget, 'uncertain');
        $reserved = $this->seedCall($org, $first, $budget, 'reserved');
        $this->seedCall($org, $first, $budget, 'completed');
        $this->seedCall($org, $first, $budget, 'failed');
        $otherBrand = $this->seedCall($org, $second, $secondBudget, 'uncertain');
        $otherOrg = $this->seedCall($other, $foreign, $foreignBudget, 'uncertain');
        $beforeCalls = DB::table('ai_calls')->orderBy('id')->get()->toArray();
        $beforeBudgets = DB::table('ai_budgets')->orderBy('id')->get()->toArray();

        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $response = $this->getJson('/ai/recovery/calls?brand_id='.$second->id.'&organization_id='.$other->id)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $ids = array_column($response->json('calls'), 'id');
        $this->assertEqualsCanonicalizing([$uncertain, $reserved], $ids);
        $this->assertNotContains($otherBrand, $ids);
        $this->assertNotContains($otherOrg, $ids);
        $this->assertSame(['id', 'status', 'family', 'reservedMicros', 'createdAt', 'updatedAt'], array_keys($response->json('calls.0')));
        $this->assertSame('100', $response->json('calls.0.reservedMicros'));
        $this->assertNull($response->json('nextCursor'));
        $this->assertStringNotContainsString('PRIVATE_', $response->getContent());
        $this->assertEquals($beforeCalls, DB::table('ai_calls')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeBudgets, DB::table('ai_budgets')->orderBy('id')->get()->toArray());
        $this->assertSame(0, DB::table('object_costs')->count());
        Http::assertNothingSent();

        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->getJson('/ai/recovery/calls')->assertOk()->assertJsonCount(1, 'calls')->assertJsonPath('calls.0.id', $otherBrand);
    }

    public function test_only_active_designated_owner_can_read(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        [$admin] = $this->member($org, 'admin');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        [$otherOwner] = $this->member($org, 'owner');
        $unverified = $this->user(false);
        $this->getJson('/ai/recovery/calls')->assertUnauthorized();
        $this->actingAs($admin)->withSession(['brand_id' => $brand->id])->getJson('/ai/recovery/calls')->assertForbidden();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->getJson('/ai/recovery/calls')->assertForbidden();
        $this->actingAs($otherOwner)->withSession(['brand_id' => $brand->id])->getJson('/ai/recovery/calls')->assertForbidden();
        $this->actingAs($unverified)->withSession(['brand_id' => $brand->id])->getJson('/ai/recovery/calls')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->getJson('/ai/recovery/calls')->assertOk()->assertJsonCount(0, 'calls');
        $owner->forceFill(['auth_version' => $owner->auth_version + 1])->save();
        $this->getJson('/ai/recovery/calls')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_owner_brand_grants_and_explicit_wrong_brand_are_enforced(): void
    {
        [$owner, $org, $first, $second, $membership] = $this->cabinet();
        $this->within($org, null, function () use ($membership, $first): void {
            $membership->brand_ids = [$first->id];
            $membership->save();
        });
        $firstBudget = $this->budget($org, $first);
        $secondBudget = $this->budget($org, $second);
        $firstId = $this->seedCall($org, $first, $firstBudget, 'uncertain');
        $this->seedCall($org, $second, $secondBudget, 'uncertain');
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->withHeader('X-Brand-Id', $second->id)->getJson('/ai/recovery/calls')->assertNotFound();
        $this->withHeader('X-Brand-Id', $first->id)->getJson('/ai/recovery/calls')->assertOk()
            ->assertJsonCount(1, 'calls')->assertJsonPath('calls.0.id', $firstId);
        Http::assertNothingSent();
    }

    public function test_keyset_pagination_is_bounded_and_invalid_cursor_is_rejected(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $budget = $this->budget($org, $brand);
        $expected = [];
        for ($i = 0; $i < 53; $i++) {
            $expected[] = $this->seedCall($org, $brand, $budget, $i % 2 ? 'uncertain' : 'reserved');
        }
        sort($expected);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $first = $this->getJson('/ai/recovery/calls')->assertOk()->assertJsonCount(50, 'calls');
        $cursor = $first->json('nextCursor');
        $this->assertSame($expected[49], $cursor);
        $second = $this->getJson('/ai/recovery/calls?after='.$cursor)->assertOk()->assertJsonCount(3, 'calls');
        $this->assertNull($second->json('nextCursor'));
        $this->assertSame($expected, array_merge(array_column($first->json('calls'), 'id'), array_column($second->json('calls'), 'id')));
        $this->getJson('/ai/recovery/calls?after=invalid')->assertUnprocessable();
        $this->getJson('/ai/recovery/calls?after[]=invalid')->assertUnprocessable();
        $this->getJson('/ai/recovery/calls?after='.strtolower($cursor))->assertOk()->assertJsonCount(3, 'calls');
        Http::assertNothingSent();
    }

    public function test_stale_brand_revision_is_rejected_even_after_switching_back(): void
    {
        [$owner, , $first, $second] = $this->cabinet();
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $this->getJson('/ai/recovery/calls')->assertOk();
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $oldRevision = app(SessionBrandSelection::class)->profileRevision($request, '0');
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->post('/brand/switch', ['brand_id' => $first->id])->assertRedirect();
        $this->withHeader('X-Brand-Revision', $oldRevision)->getJson('/ai/recovery/calls')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_unexpected_query_failure_does_not_disclose_details(): void
    {
        [$owner, , $brand] = $this->cabinet();
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'ai_calls')) {
                throw new \RuntimeException('PRIVATE_SQL_AND_PROVIDER_DATA');
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->getJson('/ai/recovery/calls')->assertServerError();
        $this->assertStringNotContainsString('PRIVATE_SQL_AND_PROVIDER_DATA', $response->getContent());
        Http::assertNothingSent();
    }
}
