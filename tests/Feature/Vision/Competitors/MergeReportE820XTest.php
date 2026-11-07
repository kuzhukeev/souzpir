<?php

namespace Tests\Feature\Vision\Competitors;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use App\Vision\Core\ReportSeal;
use App\Vision\Core\VisibilityMetrics;
use App\Vision\Core\VisionReportReader;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MergeReportE820XTest extends TenancyTestCase
{
    public function test_merge_creates_signed_revision_and_preserves_publication_and_brand_boundaries(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $scope = ['organization_id' => $org->id, 'brand_id' => $brand->id];
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, ...$scope, 'version' => 1, 'questions' => '[{"id":"q1"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        $questions = [['id' => 'q1']];
        $plan = ['questions' => $questions, 'question_set_checksum' => CanonicalJson::hash($questions), 'providers' => ['a' => 'model'], 'runs' => 1];
        DB::table('measurements')->insert(['id' => $measurement, ...$scope, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => json_encode($plan), 'profile_snapshot' => '{}', 'profile_checksum' => CanonicalJson::hash([]), 'created_at' => now(), 'updated_at' => now()]);
        $slot = ['provider' => 'a', 'question_n' => 1, 'run_no' => 1, 'qid' => 'q1', 'intent' => 'choose', 'status' => 'ok', 'protocol_valid' => true, 'mentions_complete' => true, 'fact' => ['brand_mentioned' => false, 'brand_recommended' => false], 'mentions' => [['name' => 'Альфа', 'role' => null], ['name' => 'Альфа ООО', 'role' => null]], 'sources' => []];
        $snapshot = ['measurement_id' => $measurement, 'question_set_checksum' => CanonicalJson::hash($questions), 'providers' => ['a' => 'model'], 'questions' => [['id' => 'q1']], 'runs' => 1, 'metrics' => app(VisibilityMetrics::class)->calculate([$slot], ['a'], 1, 1), 'recommendations' => ['items' => [['title' => 'Подтверждено', 'priority' => null, 'steps' => []]]]];
        DB::table('vision_reports')->insert(['id' => $report, ...$scope, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'checksum' => CanonicalJson::hash($snapshot), 'snapshot_mac' => ReportSeal::mac($org->id, $brand->id, $report, $measurement, 1, CanonicalJson::hash($snapshot)), 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), ...$scope, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $publication = (string) Str::ulid();
        $expires = now()->addDays(7)->startOfSecond();
        DB::table('report_publications')->insert(['id' => $publication, ...$scope, 'report_id' => $report, 'created_by' => $owner->id, 'token_hash' => str_repeat('a', 64), 'public_payload' => '{}', 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
        $gate = Mockery::mock(KnowledgeGate::class);
        $gate->shouldReceive('assertAllowed')->zeroOrMoreTimes();
        app()->instance(KnowledgeGate::class, $gate);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/vision/competitors/merge')->assertOk()->assertInertia(fn ($page) => $page->component('Vision/Competitors/Merge'));
        $this->post('/vision/competitors/merge', ['sources' => ['Альфа ООО'], 'target' => 'Альфа'])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('vision_reports')->where($scope)->count());
        self::assertSame(CanonicalJson::hash($snapshot), DB::table('vision_reports')->where('id', $report)->value('checksum'));
        self::assertEquals($snapshot, $this->within($org, $brand, fn () => app(VisionReportReader::class)->read($report)));
        $new = DB::table('vision_reports')->where($scope)->where('revision', 2)->first();
        $result = json_decode($new->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($report, $result['recalculated_from']);
        self::assertEquals($snapshot['recommendations'], $result['recommendations']);
        self::assertCount(1, $result['metrics']['entities']);
        self::assertSame('Альфа', $result['metrics']['entities'][0]['name']);
        self::assertSame(CanonicalJson::hash($result), $new->checksum);
        self::assertEquals($result, $this->within($org, $brand, fn () => app(VisionReportReader::class)->read($new->id)));
        self::assertSame(ReportSeal::mac($org->id, $brand->id, $new->id, $measurement, 2, $new->checksum), $new->snapshot_mac);
        self::assertSame($report, DB::table('report_publications')->where('id', $publication)->value('report_id'));
        self::assertSame($expires->toIso8601String(), Carbon::parse(DB::table('report_publications')->where('id', $publication)->value('expires_at'))->toIso8601String());
        self::assertSame(1, DB::table('report_publications')->where($scope)->count());
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', ['sources' => ['Альфа ООО'], 'target' => 'Альфа'])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/vision/competitors/merge', ['sources' => ['Альфа ООО'], 'target' => 'Альфа'])->assertNotFound();
        self::assertSame(0, DB::table('vision_reports')->where('brand_id', $other->id)->count());
        Http::assertNothingSent();
    }

    public function test_merge_leaves_every_publication_of_the_source_report_untouched_at_its_own_deadline(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $scope = ['organization_id' => $org->id, 'brand_id' => $brand->id];
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, ...$scope, 'version' => 1, 'questions' => '[{"id":"q1"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        $questions = [['id' => 'q1']];
        $plan = ['questions' => $questions, 'question_set_checksum' => CanonicalJson::hash($questions), 'providers' => ['a' => 'model'], 'runs' => 1];
        DB::table('measurements')->insert(['id' => $measurement, ...$scope, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => json_encode($plan), 'profile_snapshot' => '{}', 'profile_checksum' => CanonicalJson::hash([]), 'created_at' => now(), 'updated_at' => now()]);
        $slot = ['provider' => 'a', 'question_n' => 1, 'run_no' => 1, 'qid' => 'q1', 'intent' => 'choose', 'status' => 'ok', 'protocol_valid' => true, 'mentions_complete' => true, 'fact' => ['brand_mentioned' => false, 'brand_recommended' => false], 'mentions' => [['name' => 'Альфа', 'role' => null], ['name' => 'Альфа ООО', 'role' => null]], 'sources' => []];
        $snapshot = ['measurement_id' => $measurement, 'question_set_checksum' => CanonicalJson::hash($questions), 'providers' => ['a' => 'model'], 'questions' => [['id' => 'q1']], 'runs' => 1, 'metrics' => app(VisibilityMetrics::class)->calculate([$slot], ['a'], 1, 1), 'recommendations' => ['items' => []]];
        DB::table('vision_reports')->insert(['id' => $report, ...$scope, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'checksum' => CanonicalJson::hash($snapshot), 'snapshot_mac' => ReportSeal::mac($org->id, $brand->id, $report, $measurement, 1, CanonicalJson::hash($snapshot)), 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), ...$scope, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $active = ['id' => (string) Str::ulid(), 'expires_at' => now()->addDays(30)->startOfSecond(), 'revoked_at' => null];
        $revoked = ['id' => (string) Str::ulid(), 'expires_at' => now()->addDays(7)->startOfSecond(), 'revoked_at' => now()->subHour()->startOfSecond()];
        $expired = ['id' => (string) Str::ulid(), 'expires_at' => now()->subDay()->startOfSecond(), 'revoked_at' => null];
        foreach ([$active, $revoked, $expired] as $index => $publication) {
            DB::table('report_publications')->insert(['id' => $publication['id'], ...$scope, 'report_id' => $report, 'created_by' => $owner->id,
                'token_hash' => str_repeat((string) $index, 64), 'public_payload' => '{"n":'.$index.'}',
                'expires_at' => $publication['expires_at'], 'revoked_at' => $publication['revoked_at'], 'created_at' => now(), 'updated_at' => now()]);
        }
        $gate = Mockery::mock(KnowledgeGate::class);
        $gate->shouldReceive('assertAllowed')->zeroOrMoreTimes();
        app()->instance(KnowledgeGate::class, $gate);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])
            ->post('/vision/competitors/merge', ['sources' => ['Альфа ООО'], 'target' => 'Альфа'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $new = DB::table('vision_reports')->where($scope)->where('revision', 2)->value('id');
        self::assertNotSame($report, $new);
        foreach ([$active, $revoked, $expired] as $index => $publication) {
            $row = DB::table('report_publications')->where('id', $publication['id'])->first(['report_id', 'public_payload', 'expires_at', 'revoked_at']);
            self::assertSame($report, $row->report_id, 'публикация '.$index.' должна остаться на исходном отчёте');
            self::assertEquals(['n' => $index], json_decode($row->public_payload, true, flags: JSON_THROW_ON_ERROR));
            self::assertSame($publication['expires_at']->toIso8601String(), Carbon::parse($row->expires_at)->toIso8601String());
            self::assertSame($publication['revoked_at']?->toIso8601String(), $row->revoked_at === null ? null : Carbon::parse($row->revoked_at)->toIso8601String());
        }
        self::assertSame(3, DB::table('report_publications')->where($scope)->count());
        self::assertSame(0, DB::table('report_publications')->where('report_id', $new)->count());
    }

    public function test_missing_confirmed_report_rolls_back_alias(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        app()->instance(SnapshotView::class, new class extends SnapshotView
        {
            public function current(TenantContext $tenant): ?array
            {
                return ['metrics' => ['entities' => [['name' => 'Альфа'], ['name' => 'Альфа ООО']]]];
            }
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', ['source' => 'Альфа ООО', 'target' => 'Альфа'])->assertSessionHasErrors('source');
        self::assertSame(0, DB::table('vision_competitor_aliases')->where('brand_id', $brand->id)->count());
        self::assertSame(0, DB::table('vision_reports')->where('brand_id', $brand->id)->count());
    }
}
