<?php

namespace Tests\Feature\Vision\Measurements;

use App\Shell\Plans\Contracts\UsageQuery;
use App\Shell\Plans\Contracts\UsageReader;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementUsageTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        putenv('VISION_USAGE_ENABLED=true');
        $_ENV['VISION_USAGE_ENABLED'] = $_SERVER['VISION_USAGE_ENABLED'] = 'true';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('VISION_USAGE_ENABLED');
        unset($_ENV['VISION_USAGE_ENABLED'], $_SERVER['VISION_USAGE_ENABLED']);
    }

    public function test_only_completed_measurements_are_counted_for_the_selected_brand(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        foreach ([[$brand, 'completed'], [$brand, 'failed'], [$other, 'completed']] as [$item, $status]) {
            $set = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $item->id, 'version' => $status === 'failed' ? 2 : 1, 'questions' => '[{}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurements')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'question_set_id' => $set, 'status' => $status, 'configuration' => '{}', 'finished_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $now = CarbonImmutable::now('UTC');
        $query = new UsageQuery($now->subDay(), $now->addDay(), ['vision.measurements', 'ai.calls']);
        $metrics = app(UsageReader::class)->read(new TenantContext($org->id, $brand->id), $query)->metrics;
        self::assertSame(1, $metrics['vision.measurements']['value']);
        self::assertSame(0, $metrics['ai.calls']['value']);
        self::assertSame('ready', $metrics['vision.measurements']['status']);
        [, $foreignOrg, $foreignBrand] = $this->cabinet(false);
        try {
            app(UsageReader::class)->read(new TenantContext($org->id, $foreignBrand->id), $query);
            self::fail('Foreign brand must not be accessible.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertNotSame($org->id, $foreignOrg->id);
        }
    }
}
