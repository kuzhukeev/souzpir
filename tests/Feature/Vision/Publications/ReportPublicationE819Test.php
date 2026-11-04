<?php

namespace Tests\Feature\Vision\Publications;

use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ReportPublicationE819Test extends TenancyTestCase
{
    private function publication(string $organization, string $brand, string $user, ?string $expires = null): array
    {
        $token = bin2hex(random_bytes(32));
        $id = (string) Str::ulid();
        DB::table('report_publications')->insert([
            'id' => $id, 'organization_id' => $organization, 'brand_id' => $brand,
            'report_id' => $this->report($organization, $brand, $user), 'created_by' => $user,
            'token_hash' => hash('sha256', $token),
            'public_payload' => json_encode(['visibility' => 40.0, 'coverage' => 80.0, 'share_of_voice' => null, 'position' => 2.0, 'questions' => 3, 'allow_pdf' => true]),
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
        [$noPdfId, $noPdfToken] = $this->publication($org->id, $brand->id, $owner->id);
        DB::table('report_publications')->where('id', $noPdfId)->update(['public_payload' => json_encode(['visibility' => 40, 'coverage' => 80, 'share_of_voice' => null, 'position' => 2, 'questions' => 3, 'allow_pdf' => false])]);
        $this->get('/v/'.$noPdfToken)->assertOk()->assertDontSee('Сохранить в PDF');
        $this->get('/v/'.$token)->assertOk()->assertSee('Видимость бренда в ИИ')->assertSee('Сохранить в PDF')->assertDontSee('Личные данные')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get('/v/'.str_repeat('a', 64))->assertNotFound();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->delete('/vision/publications/'.$id)->assertRedirect();
        $this->get('/v/'.$token)->assertStatus(410)->assertDontSee('Видимость бренда в ИИ')->assertDontSee('Сохранить в PDF')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        [, $expiringToken] = $this->publication($org->id, $brand->id, $owner->id, now()->addDay()->toDateTimeString());
        $this->get('/v/'.$expiringToken)->assertOk();
        $this->travel(1)->days();
        $this->get('/v/'.$expiringToken)->assertStatus(410);
    }

    public function test_owner_publishes_safe_projection_and_receives_one_time_link(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $report = $this->report($org->id, $brand->id, $owner->id);
        DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'created_by' => $owner->id, 'result' => json_encode(['readiness' => ['lower' => 35, 'upper' => 60], 'private' => 'Секрет сайта']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('audit_runs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $other->id,
            'created_by' => $owner->id, 'result' => json_encode(['readiness' => 99]), 'created_at' => now()->addMinute(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->once()->with($report)->andReturn([
            'questions' => [['text' => 'Личные данные']],
            'metrics' => ['visibility' => ['macro_percent' => 42, 'coverage' => 80], 'panel' => ['share_of_voice' => 15, 'position_average' => 2],
                'entities' => [['name' => 'Низкий ранг', 'answers' => 1, 'is_brand' => false], ['name' => 'Альфа', 'answers' => 8, 'is_brand' => false], ['name' => 'Наш бренд', 'answers' => 10, 'is_brand' => true], ['name' => 'Бета', 'answers' => 7, 'is_brand' => false], ['name' => 'Гамма', 'answers' => 6, 'is_brand' => false], ['name' => 'Дельта', 'answers' => 5, 'is_brand' => false], ['name' => 'Эпсилон', 'answers' => 4, 'is_brand' => false]]],
            'reviewed_facts' => [['confirmed' => true, 'text' => 'Личный факт'], ['confirmed' => false, 'text' => 'Не подтверждено']],
            'recommendations' => ['items' => [['title' => 'Уточнить описание товаров private@example.test +7 999 111-22-33', 'why' => 'Секрет рекомендации']]],
            'internal_prompt' => 'Скрытый промпт',
        ]);
        app()->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/publications')->assertOk()->inertiaProps('reportBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->post('/vision/publications', ['report_id' => $report, 'ttl_days' => 7, 'return_to' => 'report', 'allow_pdf' => true])
            ->assertRedirect('/vision/report?report='.$report)->assertSessionHas('publication_url');
        $url = session('publication_url');
        $this->assertIsString($url);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('42,0%')->assertSee('Главные выводы')
            ->assertSee('Методика')->assertSee('Отчёт подготовлен EYON')->assertSee('Чаще всего среди других компаний')->assertSee('Альфа')->assertDontSee('Наш бренд')->assertDontSee('Низкий ранг')->assertSee('подтверждено: 1')
            ->assertSee('35–60')->assertSee('Уточнить описание товаров')->assertSee('Сохранить в PDF')
            ->assertDontSee('private@example.test')->assertDontSee('+7 999 111-22-33')->assertDontSee('Секрет сайта')->assertDontSee('Личный факт')->assertDontSee('Секрет рекомендации')->assertDontSee('Скрытый промпт')->assertDontSee('Личные данные');
        DB::table('audit_runs')->where('brand_id', $brand->id)->update(['result' => json_encode(['readiness' => 99])]);
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('35–60');
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
        $this->post('/vision/publications', ['report_id' => (string) Str::ulid(), 'ttl_days' => 7, 'allow_pdf' => 'invalid'])->assertSessionHasErrors('allow_pdf');
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
