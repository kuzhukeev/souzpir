<?php

namespace Tests\Feature\Vision\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class PublicationOpenE824GTest extends TenancyTestCase
{
    public function test_valid_public_open_is_logged_without_token_or_full_answers(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $report = (string) Str::ulid();
        $publication = (string) Str::ulid();
        $token = bin2hex(random_bytes(32));
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '[{}]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vision_reports')->insert(['id' => $report, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'revision' => 1, 'payload' => '{}', 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('report_publications')->insert(['id' => $publication, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'report_id' => $report, 'created_by' => $owner->id, 'token_hash' => hash('sha256', $token), 'public_payload' => json_encode(['visibility' => 40, 'coverage' => 80, 'share_of_voice' => null, 'position' => 2, 'questions' => 3, 'private_answer' => 'PRIVATE ANSWER']), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/v/'.$token)->assertOk()->assertDontSee('PRIVATE ANSWER');
        $this->get('/v/'.$token)->assertOk();
        $logs = DB::table('audit_logs')->where('action', 'vision.report.publication.opened')->get();
        self::assertCount(2, $logs);
        self::assertSame($publication, $logs[0]->subject_id);
        self::assertSame($brand->id, $logs[0]->brand_id);
        self::assertNull($logs[0]->actor_id);
        self::assertStringNotContainsString($token, json_encode($logs, JSON_THROW_ON_ERROR));
        self::assertSame(0, $logs->where('brand_id', $other->id)->count());
        DB::table('report_publications')->where('id', $publication)->update(['revoked_at' => now()]);
        $this->get('/v/'.$token)->assertStatus(410);
        DB::table('report_publications')->where('id', $publication)->update(['revoked_at' => null, 'expires_at' => now()->subSecond()]);
        $this->get('/v/'.$token)->assertStatus(410);
        $this->get('/v/'.str_repeat('a', 64))->assertNotFound();
        self::assertSame(2, DB::table('audit_logs')->where('action', 'vision.report.publication.opened')->count());
        Http::assertNothingSent();
    }
}
