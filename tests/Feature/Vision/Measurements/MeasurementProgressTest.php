<?php

namespace Tests\Feature\Vision\Measurements;

use App\Ai\CanonicalJson;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementProgressTest extends TenancyTestCase
{
    public function refreshDatabase(): void
    {
        // Gateway's fake driver verifies the call outside a database transaction.
        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        // The next TenancyTestCase creates this fixture table during setUp.
        $this->artisan('migrate:fresh');
        parent::tearDown();
    }

    private function prepare($owner, $organization, $brand, $membership): void
    {
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');
        app(TenantContextRunner::class)->run($context, function () use ($context, $organization, $brand, $owner): void {
            $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Широкий ассортимент']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($context, $source);
            $release = $repo->publish($context, $draft['id'], 1);
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')
                ->where('g.release_id', $release->releaseId())->value('f.logical_id');
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
            app(GrantRegistry::class)->issue($context, $release->releaseId(), [$fact]);
            $set = app(QuestionSets::class)->create([['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']]);
            DB::table('vision_question_approvals')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $brand->id, 'question_set_id' => $set, 'approved_by' => $owner->id,
                'providers' => json_encode(['gemini' => 'google/gemini-2.5-flash']), 'active' => true, 'created_at' => now()]);
        });
        config(['features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
    }

    private function headers(string $brand, string $revision): array
    {
        return ['X-Brand-Id' => $brand, 'X-Brand-Revision' => $revision];
    }

    public function test_fake_slot_answer_and_repeat_are_visible_for_current_brand(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        $fixtures = storage_path('app/measurement-progress-'.Str::random(8));
        mkdir($fixtures, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $fixtures]);
        $body = ['messages' => [['role' => 'user', 'content' => 'Вопрос']], 'model' => 'google/gemini-2.5-flash', 'temperature' => 0.7, 'max_tokens' => 1200];
        $fixture = $fixtures.'/'.CanonicalJson::hash($body).'.json';
        file_put_contents($fixture, json_encode(['result' => ['content' => 'Ответ бренда', 'toolCalls' => [],
            'usage' => ['promptTokens' => 2, 'completionTokens' => 3], 'model' => $body['model'], 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = $this->headers($brand->id, $revision);
        $this->withHeaders($headers)->post('/vision/runs', ['runs' => 1])->assertRedirect();
        $id = DB::table('measurements')->value('id');
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()
            ->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.answers.0.status', 'pending');
        $this->withHeaders($headers)->post('/vision/runs/'.$id.'/next')->assertRedirect();
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()
            ->assertJsonPath('data.0.answers.0.status', 'ok')->assertJsonPath('data.0.answers.0.answer_text', 'Ответ бренда');
        $this->withHeaders($headers)->post('/vision/runs/'.$id.'/repeat')->assertRedirect();
        $this->assertDatabaseCount('measurements', 2);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertJsonPath('data.0.status', 'pending');
        unlink($fixture);
        $repeat = DB::table('measurements')->where('id', '!=', $id)->value('id');
        $this->withHeaders($headers)->post('/vision/runs/'.$repeat.'/next')->assertRedirect();
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertJsonPath('data.0.status', 'partial')
            ->assertJsonPath('data.0.answers.0.status', 'failed')->assertJsonPath('data.0.answers.0.error_code', 'provider_rejected');
        Http::assertNothingSent();
    }

    public function test_wrong_brand_viewer_and_invalid_plan_cannot_execute(): void
    {
        [$owner, $organization, $brand, $other, $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = $this->headers($brand->id, $revision);
        $this->withHeaders($headers)->post('/vision/runs', ['runs' => 1])->assertRedirect();
        $id = DB::table('measurements')->value('id');
        $this->withHeaders($headers)->post('/vision/runs/not-a-plan/next')->assertNotFound();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders($this->headers($brand->id, $revision))->post('/vision/runs/'.$id.'/next')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id]);
        $this->get('/vision/runs')->assertNotFound();
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertNotFound();
        $this->assertDatabaseHas('measurement_answers', ['measurement_id' => $id, 'status' => 'pending']);
    }
}
