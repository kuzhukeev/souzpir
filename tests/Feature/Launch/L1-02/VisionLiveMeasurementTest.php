<?php

namespace Tests\Feature\Launch\L1_02;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\EvidenceStore;
use App\Vision\Core\MeasurementRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\Core\ReportPreparation;
use App\Vision\KnowledgeGrants\GrantRegistry;
use App\Vision\Measurements\RunMeasurement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class VisionLiveMeasurementTest extends TenancyTestCase
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
        $set = app(TenantContextRunner::class)->run($context, function () use ($context, $organization, $brand, $owner): string {
            $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Широкий ассортимент']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
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
        config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true,
            'features.E0-05.defaults.openrouter_key' => 'test-only', 'eyon.live_measurements_enabled' => true,
            'features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0, 'max_tokens' => 1200]]);

        return $set;
    }

    private function headers(string $brand, string $revision): array
    {
        return ['X-Brand-Id' => $brand, 'X-Brand-Revision' => $revision];
    }

    private function fakeProvider(): void
    {
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/*' => function ($request) {
            $body = $request->data();
            if ((float) ($body['temperature'] ?? null) === 0.0) {
                return Http::response(['model' => $body['model'], 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode([
                    'brand_mentioned' => false, 'brand_position' => null, 'brand_recommended' => false,
                    'mention_quote' => null, 'companies' => [], 'tone' => 'none', 'claims' => [],
                    'suspected_errors' => [], 'confidence' => 0.4,
                ], JSON_THROW_ON_ERROR)]]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 9, 'cost' => 0.000002]], 200);
            }

            return Http::response(['model' => $body['model'], 'choices' => [['finish_reason' => 'stop',
                'message' => ['content' => 'Обычный ответ без упоминания конкретных брендов.']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6, 'cost' => 0.000001]], 200);
        }]);
    }

    private function assertParserRequestSent(): void
    {
        Http::assertSent(fn ($request) => $request->data()['model'] === 'google/gemini-2.5-flash'
            && (float) $request->data()['temperature'] === 0.0 && $request->data()['max_tokens'] === 1200);
    }

    public function test_next_endpoint_runs_live_slot_evaluates_evidence_and_feeds_the_report(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        $this->fakeProvider();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = $this->headers($brand->id, $revision);
        $this->withHeaders($headers)->post('/vision/runs', ['runs' => 1])->assertRedirect();
        $id = DB::table('measurements')->value('id');
        $this->withHeaders($headers)->post('/vision/runs/'.$id.'/next')->assertRedirect();
        $answer = DB::table('measurement_answers')->where('measurement_id', $id)->first();
        self::assertSame('ok', $answer->status);
        self::assertSame('Обычный ответ без упоминания конкретных брендов.', $answer->answer_text);
        self::assertNotNull($answer->evidence_signature, 'Live evidence must be confirmed, not left fake-only.');
        self::assertNotNull($answer->evidence_mac);
        $this->assertParserRequestSent();
        $this->withHeaders($headers)->post('/vision/runs/'.$id.'/confirm')->assertRedirect();
        $this->assertDatabaseCount('snapshot_confirmations', 1);
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');
        $reportId = app(TenantContextRunner::class)->run($context, fn () => app(ReportPreparation::class)->snapshot($id));
        $payload = json_decode(DB::table('vision_reports')->where('id', $reportId)->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('available', $payload['evidence_status'], 'Report must be built from live evidence, not fake artifacts.');
    }

    public function test_queue_job_evaluates_evidence_under_the_same_condition_as_next(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $set = $this->prepare($owner, $organization, $brand, $membership);
        $this->fakeProvider();
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');
        $id = app(TenantContextRunner::class)->run($context, fn () => app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1));
        $job = new RunMeasurement($organization->id, $brand->id, $id);
        $job->handle(app(TenantContextRunner::class));
        $answer = DB::table('measurement_answers')->where('measurement_id', $id)->first();
        self::assertSame('ok', $answer->status);
        self::assertNotNull($answer->evidence_signature, 'Queue path must evaluate evidence exactly like next().');
        $this->assertParserRequestSent();
    }

    public function test_parser_failure_keeps_the_slot_ok_with_unknown_evidence_and_does_not_retry(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $set = $this->prepare($owner, $organization, $brand, $membership);
        Http::preventStrayRequests();
        Http::fake(['openrouter.ai/*' => function ($request) {
            $body = $request->data();
            if ((float) ($body['temperature'] ?? null) === 0.0) {
                return Http::response('provider error', 500);
            }

            return Http::response(['model' => $body['model'], 'choices' => [['finish_reason' => 'stop',
                'message' => ['content' => 'Ответ без упоминаний.']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 6, 'cost' => 0.000001]], 200);
        }]);
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');
        [$id, $answerId] = app(TenantContextRunner::class)->run($context, function () use ($set): array {
            $id = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $answerId = DB::table('measurement_answers')->where('measurement_id', $id)->value('id');
            self::assertSame('ok', app(MeasurementRunner::class)->measure($answerId));

            return [$id, $answerId];
        });
        $first = app(TenantContextRunner::class)->run($context, fn () => app(EvidenceStore::class)->evaluate($answerId));
        $second = app(TenantContextRunner::class)->run($context, fn () => app(EvidenceStore::class)->evaluate($answerId));
        self::assertSame('unknown', $first);
        self::assertSame('unknown', $second);
        $answer = DB::table('measurement_answers')->where('id', $answerId)->first();
        self::assertSame('ok', $answer->status, 'A parser failure must not change the already-settled slot status.');
        self::assertNull($answer->fact);
        self::assertNull($answer->evidence_signature);
        // 1 measure call + 1 parser attempt; the repeated evaluate() reuses the same
        // idempotency key and never re-sends the failed parser request (no retry loop).
        Http::assertSentCount(2);
    }
}
