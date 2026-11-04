<?php

namespace Tests\Feature\Vision\Measurements;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\KnowledgeGrants\GrantRegistry;
use App\Vision\Measurements\MeasurementSchedule;
use App\Vision\Measurements\RunMeasurement;
use Carbon\CarbonImmutable;
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

    public function test_schedule_saves_brand_settings_and_dispatches_one_job_per_slot(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        config(['eyon.measurement_queue_enabled' => true, 'eyon.measurement_schedule_enabled' => true,
            'runtime.ai_driver' => 'fake', 'features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
        $context = new TenantContext($organization->id, $brand->id, $owner->id, $membership->id, source: 'job');
        app(TenantContextRunner::class)->run($context, function (): void {
            $set = app(QuestionSets::class)->create([
                ['id' => 'q1', 'text' => 'Первый вопрос', 'intent' => 'choose'],
                ['id' => 'q2', 'text' => 'Второй вопрос', 'intent' => 'trust'],
            ]);
            DB::table('vision_question_approvals')->update(['question_set_id' => $set]);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->put('/vision/runs/schedule', ['enabled' => true, 'frequency' => 'weekly',
            'weekday' => 1, 'local_time' => '09:00', 'models' => ['google/gemini-2.5-flash']])->assertRedirect();
        $this->assertDatabaseHas('monitoring_schedules', ['brand_id' => $brand->id, 'enabled' => true, 'frequency' => 'weekly']);
        DB::table('monitoring_schedules')->where('brand_id', $brand->id)->update(['next_run_at' => now()->subMinute()]);
        app(MeasurementSchedule::class)->dispatchDue();
        $this->assertDatabaseHas('measurements', ['brand_id' => $brand->id, 'status' => 'pending']);
        $measurement = DB::table('measurements')->where('brand_id', $brand->id)->value('id');
        $this->assertDatabaseHas('audit_logs', ['brand_id' => $brand->id, 'subject_id' => $measurement, 'action' => 'vision.measurement.scheduled']);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('data.0.launch_source', 'scheduled');
        self::assertSame(2, DB::table('measurement_answers')->where('brand_id', $brand->id)->count());
        Queue::assertPushed(RunMeasurement::class, 2);
        app(MeasurementSchedule::class)->dispatchDue();
        Queue::assertPushed(RunMeasurement::class, 2);
        Http::assertNothingSent();
    }

    public function test_schedule_rejects_viewer_other_brand_and_unapproved_model(): void
    {
        [$owner, $organization, $brand, $other, $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $input = ['enabled' => true, 'frequency' => 'weekly', 'weekday' => 1, 'local_time' => '09:00',
            'models' => ['google/gemini-2.5-flash']];
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->put('/vision/runs/schedule', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->put('/vision/runs/schedule', [...$input, 'models' => ['unapproved']])->assertSessionHasErrors('schedule');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->put('/vision/runs/schedule', $input)->assertNotFound();
        $this->assertDatabaseCount('monitoring_schedules', 0);
    }

    public function test_monthly_schedule_moves_to_first_selected_weekday_of_next_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00', 'Europe/Moscow'));
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->put('/vision/runs/schedule', ['enabled' => true, 'frequency' => 'monthly', 'weekday' => 1,
                'local_time' => '09:00', 'models' => ['google/gemini-2.5-flash']])->assertRedirect();
        $next = DB::table('monitoring_schedules')->where('brand_id', $brand->id)->value('next_run_at');
        self::assertSame('2026-12-07 06:00:00', CarbonImmutable::parse($next)->utc()->format('Y-m-d H:i:s'));
    }
}
