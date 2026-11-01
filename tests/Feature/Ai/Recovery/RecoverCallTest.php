<?php

namespace Tests\Feature\Ai\Recovery;

use App\Ai\Http\RecoverCallController;
use App\Contracts\Ai\AiResult;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RecoverCallTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['features.E0-05.defaults.openrouter_key' => 'fake-only-key']);
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth', 'verified', 'tenant', 'auth.version', 'auth.nostore'])
            ->post('/ai/recovery/calls/{callId}/reconcile', RecoverCallController::class);
    }

    private function seedCall(Organization $organization, Brand $brand, string $status = 'uncertain', ?int $resultCost = null): string
    {
        $budgetId = (string) Str::ulid();
        $callId = (string) Str::ulid();
        DB::table('ai_budgets')->insert(['id' => $budgetId, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'family' => 'seller', 'period' => '2026-11-01', 'spent_micros' => 4, 'reserved_micros' => 100,
            'unresolved_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $result = $resultCost === null ? null : new AiResult('Ответ', [], ['promptTokens' => 2, 'completionTokens' => 3],
            'google/gemini-2.5-flash', 10, $resultCost, $callId);
        DB::table('ai_calls')->insert(['id' => $callId, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'budget_id' => $budgetId, 'idempotency_key_hash' => hash('sha256', $callId), 'request_hash' => hash('sha256', 'request-'.$callId),
            'family' => 'seller', 'purpose' => 'responder', 'provider' => 'gemini', 'requested_model' => 'google/gemini-2.5-flash',
            'model' => 'google/gemini-2.5-flash', 'object_type' => 'brand', 'object_id' => $brand->id,
            'data_classes' => '["knowledge_public"]', 'attempt' => 1, 'status' => $status,
            'provider_generation_id' => 'gen-'.strtolower($callId), 'reserved_micros' => 100,
            'result_encrypted' => $result ? Crypt::encryptString(json_encode($result->toArray(), JSON_THROW_ON_ERROR)) : null,
            'created_at' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]);

        return $callId;
    }

    private function provider(string $callId, string $cost, bool $valid = true): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/api/v1/generation*' => Http::response(['data' => [
            'id' => $valid ? 'gen-'.strtolower($callId) : 'gen-wrong1234',
            'cancelled' => false, 'finish_reason' => 'stop', 'total_cost' => $cost,
        ]])]);
    }

    public function test_owner_recovers_confirmed_charge_and_excess_with_audit(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $id = $this->seedCall($organization, $brand, resultCost: 150);
        $this->provider($id, '0.000150');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/ai/recovery/calls/'.$id.'/reconcile')->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('costMicros', 150);
        $call = DB::table('ai_calls')->where('id', $id)->first();
        $budget = DB::table('ai_budgets')->where('id', $call->budget_id)->first();
        $this->assertSame('completed', $call->status);
        $this->assertSame(150, (int) $call->cost_micros);
        $this->assertSame(2, (int) $call->prompt_tokens);
        $this->assertTrue((bool) $call->reserve_exceeded);
        $this->assertNotNull($call->reconciliation_evidence_hash);
        $this->assertSame(154, (int) $budget->spent_micros);
        $this->assertSame(0, (int) $budget->reserved_micros);
        $this->assertSame(0, (int) $budget->unresolved_count);
        $this->assertTrue((bool) $budget->reservation_breached);
        $this->assertSame(150, (int) DB::table('object_costs')->where('organization_id', $organization->id)
            ->where('brand_id', $brand->id)->where('object_id', $brand->id)->value('cost_micros'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'ai.call.reconciled')->where('subject_id', $id)->count());
        $this->postJson('/ai/recovery/calls/'.$id.'/reconcile')->assertServerError();
        $this->assertSame(154, (int) DB::table('ai_budgets')->where('id', $call->budget_id)->value('spent_micros'));
        Http::assertSentCount(1);
    }

    public function test_confirmed_zero_charge_releases_reserve_but_missing_proof_keeps_it(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $id = $this->seedCall($organization, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->provider($id, '0', false);
        $this->postJson('/ai/recovery/calls/'.$id.'/reconcile')->assertServerError();
        $this->assertSame('uncertain', DB::table('ai_calls')->where('id', $id)->value('status'));
        $this->provider($id, '0');
        $this->postJson('/ai/recovery/calls/'.$id.'/reconcile')->assertOk()->assertJsonPath('status', 'failed');
        $call = DB::table('ai_calls')->where('id', $id)->first();
        $this->assertSame(0, (int) $call->cost_micros);
        $this->assertSame(0, (int) DB::table('ai_budgets')->where('id', $call->budget_id)->value('reserved_micros'));
    }

    public function test_recent_reservation_stays_locked_then_confirmed_charge_without_result_is_recorded(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $id = $this->seedCall($organization, $brand, status: 'reserved');
        DB::table('ai_calls')->where('id', $id)->update(['updated_at' => now()]);
        $this->provider($id, '0.000050');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/ai/recovery/calls/'.$id.'/reconcile')->assertServerError();
        Http::assertNothingSent();
        DB::table('ai_calls')->where('id', $id)->update(['updated_at' => now()->subMinutes(20)]);
        $this->postJson('/ai/recovery/calls/'.$id.'/reconcile')->assertOk()
            ->assertJsonPath('status', 'failed')->assertJsonPath('costMicros', 50);
        $call = DB::table('ai_calls')->where('id', $id)->first();
        $this->assertSame('reconciled_billed_without_result', $call->error_code);
        $this->assertSame(54, (int) DB::table('ai_budgets')->where('id', $call->budget_id)->value('spent_micros'));
        $this->assertSame(50, (int) DB::table('object_costs')->where('organization_id', $organization->id)
            ->where('brand_id', $brand->id)->where('object_id', $brand->id)->value('cost_micros'));
    }

    public function test_access_brand_isolation_and_invalid_id(): void
    {
        [$owner, $organization, $brand, $otherBrand] = $this->cabinet();
        $id = $this->seedCall($organization, $otherBrand);
        $ownId = $this->seedCall($organization, $brand);
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->provider($id, '0');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/ai/recovery/calls/'.$id.'/reconcile')->assertServerError();
        $this->postJson('/ai/recovery/calls/bad/reconcile')->assertUnprocessable();
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->postJson('/ai/recovery/calls/'.$ownId.'/reconcile')->assertForbidden();
        $this->assertSame('uncertain', DB::table('ai_calls')->where('id', $id)->value('status'));
        $this->assertSame('uncertain', DB::table('ai_calls')->where('id', $ownId)->value('status'));
        Http::assertNothingSent();
    }
}
