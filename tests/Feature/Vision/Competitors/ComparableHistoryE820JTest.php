<?php

namespace Tests\Feature\Vision\Competitors;

use App\Tenancy\TenantContext;
use App\Vision\Competitors\ComparableCompanyHistory;
use App\Vision\Competitors\SnapshotView;
use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ComparableHistoryE820JTest extends TenancyTestCase
{
    public function test_only_full_matching_measurements_produce_company_deltas(): void
    {
        $service = app(ComparableCompanyHistory::class);
        $slot = ['qid' => 'q1', 'provider' => 'model', 'run_no' => 1, 'valid' => true, 'mentions_complete' => true, 'mentions' => [['name' => 'Альфа']]];
        $before = ['question_set_checksum' => 'hash', 'questions' => [['id' => 'q1']], 'providers' => ['model' => 'v1'], 'runs' => 1, 'metrics' => ['slots' => [$slot]]];
        $after = $before;
        $after['metrics']['slots'][0]['mentions'] = [['name' => 'Бета']];
        self::assertSame(['Альфа' => -1, 'Бета' => 1], $service->deltas($before, $after, []));
        self::assertSame(['Бета' => 0], $service->deltas($before, $after, ['Альфа' => 'Бета']));
        $after['providers'] = ['model' => 'v2'];
        self::assertNull($service->deltas($before, $after, []));
        $after = $before;
        $after['metrics']['slots'][0]['mentions_complete'] = false;
        self::assertNull($service->deltas($before, $after, []));
        $after = $before;
        $after['metrics']['slots'][0]['qid'] = 'another';
        self::assertNull($service->deltas($before, $after, []));
        $after = $before;
        $after['metrics']['slots'][0]['valid'] = false;
        self::assertNull($service->deltas($before, $after, []));
    }

    public function test_screen_uses_previous_confirmed_matching_snapshot(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $set = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode([['id' => 'q1', 'text' => 'Вопрос']]), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $measurements = [(string) Str::ulid(), (string) Str::ulid()];
        $reports = [(string) Str::ulid(), (string) Str::ulid()];
        foreach ($measurements as $index => $measurement) {
            DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => '{}', 'profile_checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('vision_reports')->insert(['id' => $reports[$index], 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => now()->addSeconds($index), 'updated_at' => now()]);
        }
        $slot = ['qid' => 'q1', 'provider' => 'model', 'run_no' => 1, 'valid' => true, 'mentions_complete' => true, 'mentions' => []];
        $before = ['measurement_id' => $measurements[0], 'question_set_checksum' => 'hash', 'questions' => [['id' => 'q1']], 'providers' => ['model' => 'v1'], 'runs' => 1, 'metrics' => ['slots' => [$slot]]];
        $slot['mentions'] = [['name' => 'Альфа', 'is_brand' => true]];
        $slot['fact'] = ['brand_mentioned' => true, 'mention_status' => 'confirmed', 'mention_quote' => 'Рекомендую Альфа'];
        $after = ['measurement_id' => $measurements[1], 'question_set_checksum' => 'hash', 'questions' => [['id' => 'q1']], 'providers' => ['model' => 'v1'], 'runs' => 1, 'metrics' => ['entities' => [['name' => 'Альфа', 'answers' => 1, 'is_brand' => true]], 'slots' => [$slot], 'intents' => []]];
        app()->instance(SnapshotView::class, new class($after) extends SnapshotView
        {
            public function __construct(private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $this->snapshot;
            }
        });
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($reports[0])->andReturn($before);
        app()->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('entities.0.delta', 1)->where('entities.0.quotes.0.text', 'Рекомендую Альфа')->where('entities.0.quotes.0.provider', 'model'));
    }

    public function test_company_screen_requires_access_and_is_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = ['metrics' => ['entities' => [['name' => 'Альфа', 'answers' => 1, 'is_brand' => false]], 'slots' => [], 'intents' => []]];
        app()->instance(SnapshotView::class, new class($brand->id, $snapshot) extends SnapshotView
        {
            public function __construct(private string $brand, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->brand ? $this->snapshot : null;
            }
        });
        $this->get('/vision/competitors')->assertRedirect();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('entities.0.delta', null));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/competitors/merge', ['source' => 'Альфа', 'target' => 'Бета'])->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/vision/competitors')->assertOk()->assertInertia(fn ($page) => $page->where('available', false)->has('entities', 0));
    }
}
