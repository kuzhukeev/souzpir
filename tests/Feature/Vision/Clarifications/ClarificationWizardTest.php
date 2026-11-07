<?php

namespace Tests\Feature\Vision\Clarifications;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Core\VisibilityMetrics;
use App\Vision\Core\VisionReportReader;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use App\Vision\Reports\ClarificationVisibility;
use App\Vision\Reports\ReportData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClarificationWizardTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app()->instance(KnowledgeGate::class, new class implements KnowledgeGate
        {
            public function assertAllowed(TenantContext $tenant, string $measurementId, #[\SensitiveParameter] array $frozenProfile): void {}
        });
    }

    private function measurement($org, $brand): string
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $set = (string) Str::ulid();
        $id = (string) Str::ulid();
        $questions = [['id' => 'q1', 'text' => 'Вопрос']];
        $profile = ['synthetic' => true];
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'version' => 1, 'questions' => json_encode($questions), 'size' => 1, 'checksum' => CanonicalJson::hash($questions),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => json_encode($profile),
            'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'measurement_id' => $id, 'question_n' => 1, 'provider' => 'fake',
            'requested_model' => 'fake-model', 'run_no' => 1, 'status' => 'ok', 'answer_text' => 'Ответ о бренде',
            'fact' => json_encode(['value' => 'Работает с 2009 года', 'identity_candidates' => ['Союз-Пир СПб'],
                'presence_candidates' => ['2gis.ru']]), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function confirm($user, $brand, string $measurement): void
    {
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->post('/vision/runs/'.$measurement.'/confirm')->assertRedirect();
    }

    public function test_full_path_saves_answers_and_can_resume_by_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $base = "/vision/runs/{$measurement}/clarify";
        $this->get("{$base}/mentions")->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
        $this->confirm($owner, $brand, $measurement);
        $mention = $this->get("{$base}/mentions")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/mentions", ['item_key' => $mention['key'], 'decision' => 'yes', 'add_alias' => true])->assertRedirect();
        $presence = $this->get("{$base}/presence")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/presence", ['item_key' => $presence['key'], 'decision' => 'unknown',
            'profile_url' => 'https://2gis.ru/example'])->assertRedirect();
        $fact = $this->get("{$base}/facts")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/facts", ['item_key' => $fact['key'], 'decision' => 'outdated',
            'correction' => 'Работает с 2010 года'])->assertRedirect();
        $this->get("{$base}/done")->assertOk()->assertInertia(fn ($page) => $page->where('total', 3)->where('pending', 1));
        $this->get("{$base}/facts")->assertOk()->assertInertia(fn ($page) => $page->where('items.0.decision', 'outdated'));
        $this->assertDatabaseCount('vision_clarification_decisions', 3);
        $this->assertDatabaseHas('vision_clarification_decisions', ['brand_id' => $brand->id, 'decision' => 'outdated']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get("{$base}/facts")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_invalid_item_decision_and_correction_are_rejected(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        $base = "/vision/runs/{$measurement}/clarify/facts";
        $fact = $this->get($base)->assertOk()->inertiaProps('items.0');
        $this->post($base, ['item_key' => str_repeat('a', 64), 'decision' => 'correct'])->assertSessionHasErrors('item_key');
        $this->post($base, ['item_key' => $fact['key'], 'decision' => 'unknown'])->assertSessionHasErrors('decision');
        $this->post($base, ['item_key' => $fact['key'], 'decision' => 'incorrect'])->assertSessionHasErrors('correction');
        $this->assertDatabaseCount('vision_clarification_decisions', 0);
        Http::assertNothingSent();
    }

    public function test_index_shows_only_confirmed_measurements_of_current_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $own = $this->measurement($org, $brand);
        $foreign = $this->measurement($org, $other);
        $this->confirm($owner, $brand, $own);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id, 'measurement_id' => $foreign, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/clarifications')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Vision/Clarifications/Index')->has('measurements', 1)->where('measurements.0.measurement_id', $own));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/clarifications')->assertOk()->assertInertia(fn ($page) => $page->has('measurements', 1));
        Http::assertNothingSent();
    }

    public function test_member_without_write_right_cannot_change_decision(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        $base = "/vision/runs/{$measurement}/clarify/facts";
        $fact = $this->get($base)->assertOk()->inertiaProps('items.0');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post($base, ['item_key' => $fact['key'], 'decision' => 'correct'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_confirmed_identity_recalculates_display_metrics_without_mutating_snapshot_or_other_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        $path = "/vision/runs/{$measurement}/clarify/mentions";
        $item = $this->get($path)->assertOk()->inertiaProps('items.0');
        $answerId = DB::table('answer_facts')->where('brand_id', $brand->id)->value('measurement_answer_id');
        $sourceFact = ['value' => 'Работает с 2009 года', 'identity_candidates' => ['Союз-Пир СПб'],
            'presence_candidates' => ['2gis.ru'], 'brand_mentioned' => false, 'mention_status' => 'possible', 'companies' => []];
        DB::table('answer_facts')->where('measurement_answer_id', $answerId)->update(['fact' => json_encode($sourceFact)]);
        $slot = ['id' => $answerId, 'provider' => 'fake', 'question_n' => 1, 'run_no' => 1,
            'status' => 'ok', 'protocol_valid' => true, 'fact' => $sourceFact,
            'mentions' => [], 'sources' => []];
        $metrics = app(VisibilityMetrics::class)->calculate([$slot], ['fake'], 1, 1);
        $payload = ['measurement_id' => $measurement, 'providers' => ['fake' => 'fake-model'],
            'questions' => [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']], 'runs' => 1, 'metrics' => $metrics, 'reviewed_facts' => []];
        self::assertNull($metrics['visibility']['macro_percent']);

        $this->post($path, ['item_key' => $item['key'], 'decision' => 'yes'])->assertSessionHasNoErrors();
        $factPath = "/vision/runs/{$measurement}/clarify/facts";
        $fact = $this->get($factPath)->assertOk()->inertiaProps('items.0');
        $this->post($factPath, ['item_key' => $fact['key'], 'decision' => 'incorrect', 'correction' => 'Работает с 2010 года'])->assertSessionHasNoErrors();
        $result = app(ClarificationVisibility::class)->apply(new TenantContext($org->id, $brand->id), $payload);
        self::assertSame(1, $result['applied']);
        self::assertSame(100.0, $result['metrics']['visibility']['macro_percent']);
        self::assertNull($payload['metrics']['visibility']['macro_percent']);
        self::assertSame(0, app(ClarificationVisibility::class)->apply(new TenantContext($org->id, $other->id), $payload)['applied']);

        $report = (string) Str::ulid();
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64),
            'created_at' => now(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($report)->andReturn($payload);
        app()->instance(VisionReportReader::class, $reader);
        $display = app(ReportData::class)->read(new TenantContext($org->id, $brand->id), $report)['report'];
        self::assertSame(100.0, $display['visibility']);
        self::assertNull($display['visibility_before']);
        self::assertSame(1, $display['clarifications_applied']);
        self::assertSame('incorrect', $display['sections']['facts'][0]['classification']);
        self::assertSame('Работает с 2010 года', $display['sections']['facts'][0]['correction']);
        self::assertSame(1, $display['verified_errors']);
        self::assertSame('{}', DB::table('vision_reports')->where('id', $report)->value('payload'));

        $this->post($path, ['item_key' => $item['key'], 'decision' => 'no'])->assertSessionHasNoErrors();
        $result = app(ClarificationVisibility::class)->apply(new TenantContext($org->id, $brand->id), $payload);
        self::assertSame(0.0, $result['metrics']['visibility']['macro_percent']);
    }

    public function test_done_step_recalculates_and_persists_new_revision_without_mutating_source(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        $path = "/vision/runs/{$measurement}/clarify/mentions";
        $item = $this->get($path)->assertOk()->inertiaProps('items.0');
        $answerId = DB::table('answer_facts')->where('brand_id', $brand->id)->value('measurement_answer_id');
        $sourceFact = ['value' => 'Работает с 2009 года', 'identity_candidates' => ['Союз-Пир СПб'],
            'presence_candidates' => ['2gis.ru'], 'brand_mentioned' => false, 'mention_status' => 'possible', 'companies' => []];
        DB::table('answer_facts')->where('measurement_answer_id', $answerId)->update(['fact' => json_encode($sourceFact)]);
        $slotA = ['id' => $answerId, 'provider' => 'fake', 'question_n' => 1, 'run_no' => 1, 'status' => 'ok',
            'protocol_valid' => true, 'fact' => $sourceFact, 'mentions' => [], 'sources' => []];
        $slotB = ['id' => (string) Str::ulid(), 'provider' => 'fake2', 'question_n' => 1, 'run_no' => 1, 'status' => 'ok',
            'protocol_valid' => true, 'fact' => ['brand_mentioned' => false, 'mention_status' => 'not_mentioned'], 'mentions' => [], 'sources' => []];
        $metrics = app(VisibilityMetrics::class)->calculate([$slotA, $slotB], ['fake', 'fake2'], 1, 1);
        self::assertSame(0.0, $metrics['visibility']['macro_percent']);
        $payload = ['measurement_id' => $measurement, 'providers' => ['fake' => 'fake-model', 'fake2' => 'fake2-model'],
            'questions' => [['id' => 'q1', 'text' => 'Вопрос', 'intent' => 'choose']], 'runs' => 1, 'metrics' => $metrics, 'reviewed_facts' => []];

        $this->post($path, ['item_key' => $item['key'], 'decision' => 'yes'])->assertSessionHasNoErrors();

        $reportId = (string) Str::ulid();
        DB::table('vision_reports')->insert(['id' => $reportId, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64),
            'created_at' => now(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($reportId)->andReturn($payload);
        app()->instance(VisionReportReader::class, $reader);

        $this->get("/vision/runs/{$measurement}/clarify/done")->assertOk()->assertInertia(fn ($page) => $page
            ->where('recalculated.before', 0)->where('recalculated.after', 50));

        $this->assertDatabaseCount('vision_reports', 2);
        $new = DB::table('vision_reports')->where('measurement_id', $measurement)->orderByDesc('revision')->first();
        self::assertSame(2, $new->revision);
        $newPayload = json_decode($new->payload, true);
        self::assertSame(50.0, $newPayload['metrics']['visibility']['macro_percent']);
        self::assertSame($reportId, $newPayload['clarified_from']);
        self::assertSame('{}', DB::table('vision_reports')->where('id', $reportId)->value('payload'));
        Http::assertNothingSent();
    }

    public function test_done_step_does_not_recalculate_for_viewer_without_write_right(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->get("/vision/runs/{$measurement}/clarify/done")->assertOk()
            ->assertInertia(fn ($page) => $page->where('recalculated', null));
        $this->assertDatabaseCount('vision_reports', 0);
        Http::assertNothingSent();
    }
}
