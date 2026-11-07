<?php

namespace Tests\Feature\Launch\L1_01;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class VisionMeasurementBudgetStopTest extends TenancyTestCase
{
    public function refreshDatabase(): void
    {
        // Gateway's live driver verifies the call outside a database transaction.
        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh');
        parent::tearDown();
    }

    private function prepare($owner, $organization, $brand, $membership): string
    {
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');

        return app(TenantContextRunner::class)->run($context, function () use ($context, $organization, $brand, $owner): string {
            $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Широкий ассортимент']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [],
                'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($context, $source);
            $release = $repo->publish($context, $draft['id'], 1);
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                ->where('g.release_id', $release->releaseId())->value('f.logical_id');
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(
                ['ai_data_policy' => ['knowledge_public' => ['any'], 'vision_question' => ['any']]], JSON_THROW_ON_ERROR
            )]);
            app(GrantRegistry::class)->issue($context, $release->releaseId(), [$fact]);
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Какой бренд лучше по ассортименту?', 'intent' => 'choose']]);
            DB::table('vision_question_approvals')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $brand->id, 'question_set_id' => $set, 'approved_by' => $owner->id,
                'providers' => json_encode(['gemini' => 'google/gemini-2.5-flash']), 'active' => true, 'created_at' => now()]);

            return $set;
        });
    }

    public function test_budget_exhausted_fails_the_claimed_and_remaining_pending_slots(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $set = $this->prepare($owner, $organization, $brand, $membership);
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only',
            'eyon.live_measurements_enabled' => true, 'features.L1-01.defaults.global_daily_usd' => 0.00001]);
        Http::preventStrayRequests();
        Http::fake();
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');
        $id = app(TenantContextRunner::class)->run($context, fn () => app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 2));
        $answers = DB::table('measurement_answers')->where('measurement_id', $id)->orderBy('run_no')->pluck('id');
        self::assertCount(2, $answers);
        $status = app(TenantContextRunner::class)->run($context, fn () => app(MeasurementRunner::class)->measure($answers[0]));
        self::assertSame('failed', $status);
        $claimed = DB::table('measurement_answers')->where('id', $answers[0])->first();
        self::assertSame('failed', $claimed->status);
        self::assertSame('budget_exhausted', $claimed->error_code);
        $sibling = DB::table('measurement_answers')->where('id', $answers[1])->first();
        self::assertSame('failed', $sibling->status, 'The still-pending sibling slot must not be claimed once the budget is exhausted.');
        self::assertSame('budget_exhausted', $sibling->error_code);
        self::assertSame('failed', DB::table('measurements')->where('id', $id)->value('status'));
        Http::assertNothingSent();
    }
}
