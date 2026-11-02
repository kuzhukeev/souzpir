<?php

namespace Tests\Feature\Vision\Measurements;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\KnowledgeGrants\GrantRegistry;
use App\Vision\Measurements\RunMeasurement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementStartTest extends TenancyTestCase
{
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
    }

    public function test_owner_starts_only_current_brands_approved_set(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $first, $second, $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $first, $membership);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'brand_id' => $second->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        config(['features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $first->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->post('/vision/runs', ['runs' => 1])->assertRedirect('/vision/runs');
        $this->assertDatabaseHas('measurements', ['organization_id' => $organization->id, 'brand_id' => $first->id, 'status' => 'pending']);
        $this->assertDatabaseCount('measurement_answers', 1);
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->get('/vision/runs')->assertNotFound();
        $this->withHeaders($headers)->post('/vision/runs', ['runs' => 1])->assertNotFound();
        $this->assertDatabaseCount('measurements', 1);
        Http::assertNothingSent();
    }

    public function test_viewer_and_bad_repeat_count_cannot_start(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->post('/vision/runs', ['runs' => 1])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->post('/vision/runs', ['runs' => 0])->assertSessionHasErrors('runs');
        $this->assertDatabaseCount('measurements', 0);
    }

    public function test_queue_option_dispatches_brand_scoped_measurement(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        config(['eyon.measurement_queue_enabled' => true, 'runtime.ai_driver' => 'fake', 'features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->post('/vision/runs', ['runs' => 1])->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(RunMeasurement::class, fn ($job) => $job->organizationId === $organization->id && $job->brandId === $brand->id);
        Http::assertNothingSent();
    }
}
