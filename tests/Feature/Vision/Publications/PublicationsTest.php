<?php

namespace Tests\Feature\Vision\Publications;

use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationsTest extends TenancyTestCase
{
    private function publication(string $organization, string $brand, string $user, ?string $expires = null): array
    {
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::ulid();
        DB::table('report_publications')->insert([
            'id' => $id, 'organization_id' => $organization, 'brand_id' => $brand,
            'report_id' => $this->report($organization, $brand, $user), 'created_by' => $user,
            'token_hash' => hash('sha256', $token),
            'public_payload' => json_encode(['visibility' => 40.0, 'coverage' => 80.0, 'share_of_voice' => null, 'position' => 2.0, 'questions' => 3]),
            'expires_at' => $expires ?? now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $token];
    }

    private function report(string $organization, string $brand, string $user): string
    {
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        $version = DB::table('question_sets')->where('brand_id', $brand)->count() + 1;
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization, 'brand_id' => $brand, 'version' => $version,
            'questions' => '[{"id":"q1","text":"Вопрос","intent":"general"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization, 'brand_id' => $brand,
            'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $organization, 'brand_id' => $brand,
            'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $brand, 'measurement_id' => $measurement, 'confirmed_by' => $user, 'created_at' => now(), 'updated_at' => now()]);

        return $report;
    }

    public function test_public_report_expires_and_revokes_with_no_store_headers(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$id, $token] = $this->publication($org->id, $brand->id, $owner->id);
        $this->get('/v/'.$token)->assertOk()->assertSee('Видимость бренда в ИИ')->assertDontSee('Личные данные')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get('/v/'.str_repeat('a', 64))->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->delete('/vision/publications/'.$id)->assertRedirect();
        $this->get('/v/'.$token)->assertStatus(410)->assertDontSee('Видимость бренда в ИИ')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        [, $expiringToken] = $this->publication($org->id, $brand->id, $owner->id, now()->addDay()->toDateTimeString());
        $this->get('/v/'.$expiringToken)->assertOk();
        $this->travel(1)->days();
        $this->get('/v/'.$expiringToken)->assertStatus(410);
    }

    public function test_owner_publishes_safe_projection_and_receives_one_time_link(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $report = $this->report($org->id, $brand->id, $owner->id);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($report)->andReturn([
            'questions' => [['text' => 'Личные данные']],
            'metrics' => ['visibility' => ['macro_percent' => 42, 'coverage' => 80], 'panel' => ['share_of_voice' => 15, 'position_average' => 2]],
            'internal_prompt' => 'Скрытый промпт',
        ]);
        app()->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->post('/vision/publications', ['report_id' => $report, 'ttl_days' => 7, 'return_to' => 'report'])
            ->assertRedirect('/vision/report?report='.$report)->assertSessionHas('publication_url');
        $url = session('publication_url');
        $this->assertIsString($url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('42,0%')->assertSee('Главные выводы')
            ->assertSee('Методика')->assertDontSee('Скрытый промпт')->assertDontSee('Личные данные');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'vision.report.published')->where('brand_id', $brand->id)->count());
    }

    public function test_brand_scope_rights_and_bad_duration(): void
    {
        [$owner, $org, $first, $second] = $this->cabinet();
        foreach ([$first, $second] as $brand) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
                'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        [$id] = $this->publication($org->id, $first->id, $owner->id);
        $this->actingAs($owner)->withSession(['brand_id' => $first->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $first->id, 'X-Brand-Revision' => $revision])->post('/vision/publications', ['report_id' => (string) Str::ulid(), 'ttl_days' => 99])->assertSessionHasErrors('ttl_days');
        $this->post('/vision/publications', ['report_id' => (string) Str::ulid(), 'ttl_days' => 7, 'return_to' => 'external'])->assertSessionHasErrors('return_to');
        $foreignReport = $this->report($org->id, $second->id, $owner->id);
        $this->post('/vision/publications', ['report_id' => $foreignReport, 'ttl_days' => 7])->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $second->id])->assertRedirect();
        $this->withHeaders(['X-Brand-Id' => $second->id, 'X-Brand-Revision' => $revision])->delete('/vision/publications/'.$id)->assertStatus(409);
        [$viewer] = $this->member($org, 'viewer', [$first->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $first->id]);
        $this->get('/vision/publications')->assertNotFound();
        $this->withHeaders(['X-Brand-Id' => $first->id, 'X-Brand-Revision' => $revision])->delete('/vision/publications/'.$id)->assertForbidden();
    }
}
