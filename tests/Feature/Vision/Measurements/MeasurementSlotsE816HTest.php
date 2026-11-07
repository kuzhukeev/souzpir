<?php

namespace Tests\Feature\Vision\Measurements;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\QuestionSets;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementSlotsE816HTest extends TenancyTestCase
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

    private function assignPlan(string $organizationId, ?int $slotsMonth): void
    {
        $code = 'e816h-test-'.Str::random(8);
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => $code, 'name' => 'Test', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()]);
        if ($slotsMonth !== null) {
            DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'vision.slots_month', 'value' => $slotsMonth, 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_sufficient_slots_shown_as_available_and_remaining(): void
    {
        Http::preventStrayRequests();
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        $this->assignPlan($organization->id, 300);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $data = $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->json();
        self::assertSame(300, $data['slots']['limit']);
        self::assertSame(0, $data['slots']['used']);
        self::assertSame(300, $data['slots']['available']);
        self::assertSame(1, $data['plan']['questions']);
        self::assertSame(1, $data['plan']['models']);
        $this->withHeaders($headers)->post('/vision/runs', ['runs' => 1])->assertRedirect('/vision/runs')->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_insufficient_slots_blocks_start(): void
    {
        [$owner, $organization, $brand, , $membership] = $this->cabinet();
        $this->prepare($owner, $organization, $brand, $membership);
        // Plan needs 1 question x 1 model x 1 run = 1 slot; limit 0 cannot cover it.
        $this->assignPlan($organization->id, 0);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $data = $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->json();
        self::assertSame(0, $data['slots']['limit']);
        self::assertSame(0, $data['slots']['available']);
        $this->withHeaders($headers)->post('/vision/runs', ['runs' => 1])->assertSessionHasErrors('runs');
        $this->assertDatabaseCount('measurements', 0);
    }
}
