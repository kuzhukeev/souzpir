<?php

namespace Tests\Feature\Vision\Settings;

use App\Vision\Core\VisionReportReader;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PersonalReportAccessE824HTest extends TenancyTestCase
{
    public function test_personal_link_is_scoped_expires_and_can_be_revoked(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{"id":"q1","text":"Question"}]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('snapshot_confirmations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'confirmed_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with($report)->andReturn(['questions' => [['id' => 'q1', 'text' => 'secret full answer']], 'metrics' => ['questions' => [], 'slots' => [['text' => 'private slot response']]], 'recommendations' => ['items' => []]]);
        $this->app->instance(VisionReportReader::class, $reader);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/settings')->assertOk()->inertiaProps('revision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $input = ['name' => 'Recipient', 'email' => 'recipient@example.test', 'report_id' => $report];
        $this->withHeaders($headers)->post('/vision/settings/personal-report-access', [...$input, 'email' => 'invalid'])->assertSessionHasErrors('email');
        $this->withHeaders($headers)->post('/vision/settings/personal-report-access', $input)->assertRedirect()->assertSessionHasNoErrors();
        $url = session('personal_report_url');
        self::assertIsString($url);
        $this->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->where('personalReportUrl', $url));
        $access = DB::table('vision_personal_report_access')->first();
        $publication = DB::table('report_publications')->where('id', $access->publication_id)->first();
        self::assertSame(hash('sha256', basename(parse_url($url, PHP_URL_PATH))), $publication->token_hash);
        self::assertGreaterThan(29, now()->diffInDays($publication->expires_at));
        self::assertLessThanOrEqual(30, now()->diffInDays($publication->expires_at));
        Auth::logout();
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertDontSee('secret full answer')->assertDontSee('private slot response');
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        DB::table('report_publications')->where('id', $publication->id)->update(['expires_at' => now()->subSecond()]);
        $this->get(parse_url($url, PHP_URL_PATH))->assertStatus(410);
        DB::table('report_publications')->where('id', $publication->id)->update(['expires_at' => now()->addDays(30)]);
        $this->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->has('personalAccess', 1)->has('personalAccess.0.opens', 1));
        $this->flushHeaders()->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $otherPage = $this->withSession(['personal_report_url' => $url, 'personal_report_brand' => $brand->id])->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->has('personalAccess', 0)->where('personalReportUrl', null));
        $otherRevision = $otherPage->inertiaProps('revision');
        $otherHeaders = ['X-Brand-Id' => $other->id, 'X-Brand-Revision' => $otherRevision];
        $this->withHeaders($otherHeaders)->delete('/vision/settings/personal-report-access/'.$access->id)->assertNotFound();
        $this->withHeaders($otherHeaders)->post('/vision/settings/personal-report-access', $input)->assertNotFound();
        $this->flushHeaders()->post('/brand/switch', ['brand_id' => $brand->id])->assertRedirect();
        $revision = $this->get('/vision/settings')->assertOk()->inertiaProps('revision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])->delete('/vision/settings/personal-report-access/'.$access->id)->assertRedirect();
        $this->get(parse_url($url, PHP_URL_PATH))->assertStatus(410);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->flushHeaders()->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $viewerPage = $this->get('/vision/settings')->assertOk()->assertInertia(fn ($page) => $page->has('personalAccess', 0)->where('personalReportUrl', null));
        $viewerRevision = $viewerPage->inertiaProps('revision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $viewerRevision])->post('/vision/settings/personal-report-access', $input)->assertForbidden();
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }
}
