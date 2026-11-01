<?php

namespace Tests\Feature\Shell\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AiRecoveryPageTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_only_the_active_designated_owner_receives_the_page_and_session_bound_revision(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        [$admin] = $this->member($organization, 'admin');
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        [$otherOwner] = $this->member($organization, 'owner');
        $this->get('/billing/ai-recovery')->assertRedirect('/login');
        foreach ([$admin, $viewer, $otherOwner] as $actor) {
            $this->actingAs($actor)->withSession(['brand_id' => $brand->id])->get('/billing/ai-recovery')->assertForbidden();
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->get('/billing/ai-recovery')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Billing/AiRecovery', false)->has('aiRecoveryRevision')->missing('calls'));
        $revision = $response->inertiaProps('aiRecoveryRevision');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $revision);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/billing')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', true));
        $owner->forceFill(['auth_version' => $owner->auth_version + 1])->save();
        $this->getJson('/billing/ai-recovery')->assertUnauthorized();
        $this->getJson('/ai/recovery/calls', ['X-Brand-Revision' => $revision])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_page_revision_binds_real_api_reads_to_the_selected_brand_and_session(): void
    {
        [$owner, $organization, $first, $second] = $this->cabinet();
        foreach ([$first, $second] as $brand) {
            $budget = (string) Str::ulid();
            DB::table('ai_budgets')->insert(['id' => $budget, 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'family' => 'seller', 'period' => now()->startOfMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ai_calls')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id, 'brand_id' => $brand->id, 'budget_id' => $budget, 'idempotency_key_hash' => hash('sha256', $brand->id), 'request_hash' => hash('sha256', 'request-'.$brand->id), 'family' => 'seller', 'purpose' => 'private-purpose', 'provider' => 'private-provider', 'requested_model' => 'private-model', 'model' => 'private-model', 'object_type' => 'brand', 'object_id' => $brand->id, 'data_classes' => '[]', 'attempt' => 1, 'status' => 'uncertain', 'reserved_micros' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $page = $this->get('/billing/ai-recovery')->assertOk();
        $revision = $page->inertiaProps('aiRecoveryRevision');
        $expected = DB::table('ai_calls')->where('brand_id', $first->id)->value('id');
        $response = $this->getJson('/ai/recovery/calls', ['X-Brand-Id' => $first->id, 'X-Brand-Revision' => $revision])
            ->assertOk()->assertJsonCount(1, 'calls')->assertJsonPath('calls.0.id', $expected);
        $this->assertStringNotContainsString('private-', $response->getContent());
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->getJson('/ai/recovery/calls', ['X-Brand-Revision' => $revision])->assertStatus(409);
        $newRevision = $this->get('/billing/ai-recovery')->assertOk()->inertiaProps('aiRecoveryRevision');
        $this->assertNotSame($revision, $newRevision);
        $other = DB::table('ai_calls')->where('brand_id', $second->id)->value('id');
        $this->getJson('/ai/recovery/calls', ['X-Brand-Id' => $second->id, 'X-Brand-Revision' => $newRevision])
            ->assertOk()->assertJsonCount(1, 'calls')->assertJsonPath('calls.0.id', $other);
        Http::assertNothingSent();
    }
}
