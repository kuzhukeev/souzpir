<?php

namespace Tests\Feature\Ai\Recovery;

use App\Shell\Billing\Http\OperatorAiRecoveryController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class OperatorCostE005GTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_test/internal/ai-costs/{brand}', OperatorAiRecoveryController::class);
    }

    protected function tearDown(): void
    {
        putenv('AI_RECOVERY_OPERATOR_USER_ID');
        parent::tearDown();
    }

    private function seedCall(string $org, string $brand, ?string $proof, int $cost): string
    {
        $budget = DB::table('ai_budgets')->where('organization_id', $org)->where('brand_id', $brand)->value('id');
        if (! $budget) {
            $budget = (string) Str::ulid();
            DB::table('ai_budgets')->insert(['id' => $budget, 'organization_id' => $org, 'brand_id' => $brand, 'family' => 'seller', 'period' => now()->startOfMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = (string) Str::ulid();
        DB::table('ai_calls')->insert(['id' => $id, 'organization_id' => $org, 'brand_id' => $brand, 'budget_id' => $budget, 'idempotency_key_hash' => hash('sha256', $id), 'request_hash' => hash('sha256', 'request-'.$id), 'family' => 'seller', 'purpose' => 'test', 'provider' => 'fake', 'requested_model' => 'fake', 'model' => 'fake', 'object_type' => 'brand', 'object_id' => $brand, 'data_classes' => '[]', 'attempt' => 1, 'status' => 'completed', 'reserved_micros' => 100, 'cost_micros' => $cost, 'reconciliation_evidence_hash' => $proof, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_only_platform_operator_sees_requested_brand_on_separate_screen(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $verified = $this->seedCall($org->id, $brand->id, hash('sha256', 'verified'), 150);
        $this->seedCall($org->id, $brand->id, null, 400);
        $foreign = $this->seedCall($org->id, $other->id, hash('sha256', 'other'), 900);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        putenv('AI_RECOVERY_OPERATOR_USER_ID');
        $this->get('/_test/internal/ai-costs/'.$brand->id)->assertForbidden();
        $this->get('/billing/ai-recovery')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('confirmedCosts'));
        putenv('AI_RECOVERY_OPERATOR_USER_ID='.$owner->id);
        $this->get('/billing/ai-recovery')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('confirmedCosts'));
        putenv('AI_RECOVERY_OPERATOR_USER_ID='.$viewer->id);
        $this->get('/_test/internal/ai-costs/'.$brand->id)->assertForbidden();
        $this->actingAs($viewer)->get('/_test/internal/ai-costs/'.$brand->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Billing/OperatorAiRecovery')->has('confirmedCosts', 1)->where('confirmedCosts.0.id', $verified)->where('confirmedCosts.0.usd', '0.000150'));
        $this->get('/_test/internal/ai-costs/'.$other->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('confirmedCosts', 1)->where('confirmedCosts.0.id', $foreign)->where('confirmedCosts.0.usd', '0.000900'));
        $this->get('/_test/internal/ai-costs/not-a-brand')->assertNotFound();
        $this->actingAs($owner)->get('/billing/ai-recovery')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('confirmedCosts'));
        Http::assertNothingSent();
    }

    public function test_operator_sees_empty_state_without_evidence(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        putenv('AI_RECOVERY_OPERATOR_USER_ID='.$owner->id);
        $this->actingAs($owner)->get('/_test/internal/ai-costs/'.$brand->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('confirmedCosts', 0));
    }
}
