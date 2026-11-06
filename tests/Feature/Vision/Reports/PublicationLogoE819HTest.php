<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationLogoE819HTest extends TenancyTestCase
{
    public function test_public_logo_is_explicit_scoped_and_part_of_the_immutable_snapshot(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
                'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'version' => 1, 'questions' => '[{"id":"q1","text":"Вопрос"}]', 'size' => 1,
            'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->times(3)->andReturn([
            'questions' => [['id' => 'q1', 'text' => 'Вопрос']],
            'metrics' => ['questions' => ['q1' => ['planned' => 1, 'mention_denominator' => 1, 'mentioned' => 0]], 'slots' => []],
            'recommendations' => ['items' => [['title' => 'Update catalog', 'why' => 'Reason', 'question_id' => 'q1', 'priority' => 'High', 'steps' => ['Review', 'Publish private@example.test']]]],
        ]);
        $this->app->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $input = ['report_id' => $report, 'ttl_days' => 7];
        Storage::fake('local');
        $path = 'organizations/'.$organization->id.'/onboarding/logos/'.str_repeat('a', 32).'.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aWQAAAAASUVORK5CYII=');
        Storage::disk('local')->put($path, $png);
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['logo_path' => $path])]);
        $this->get('/vision/report')->assertOk()->assertInertia(fn ($page) => $page->where('logoAvailable', true));
        $this->withHeaders($headers)->post('/vision/publications', [...$input, 'include_logo' => false])->assertRedirect()->assertSessionHasNoErrors();
        $first = DB::table('report_publications')->where('report_id', $report)->first();
        self::assertArrayNotHasKey('logo', json_decode($first->public_payload, true));
        $this->withHeaders($headers)->post('/vision/publications', [...$input, 'include_logo' => true])->assertRedirect()->assertSessionHasNoErrors();
        $last = DB::table('report_publications')->where('report_id', $report)->orderByDesc('id')->first();
        $logo = json_decode($last->public_payload, true)['logo'];
        self::assertSame('data:image/png;base64,'.base64_encode($png), $logo);
        $url = session('publication_url');
        Storage::disk('local')->delete($path);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee($logo, false)->assertDontSee($path);
        $this->withHeaders($headers)->delete('/vision/publications/'.$last->id)->assertRedirect();
        $this->get(parse_url($url, PHP_URL_PATH))->assertStatus(410)->assertDontSee($logo, false);
        DB::table('report_publications')->where('id', $last->id)->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->get(parse_url($url, PHP_URL_PATH))->assertStatus(410)->assertDontSee($logo, false);
        $this->withHeaders($headers)->post('/vision/publications', [...$input, 'include_logo' => true])->assertSessionHasErrors('include_logo');
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/vision/publications', [...$input, 'include_logo' => true])->assertNotFound();
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/publications', [...$input, 'include_logo' => true])->assertForbidden();

    }
}
