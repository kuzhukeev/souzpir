<?php

namespace Tests\Feature\Vision\Measurements;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementMonthTest extends TenancyTestCase
{
    public function test_month_filter_is_validated_and_scoped_to_the_current_brand(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$brand, '2026-10-12'], [$brand, '2026-11-12'], [$other, '2026-10-13']] as [$item, $created]) {
            $set = (string) Str::ulid();
            DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $item->id, 'version' => DB::table('question_sets')->where('brand_id', $item->id)->count() + 1, 'questions' => '["Вопрос"]', 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('measurements')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'question_set_id' => $set, 'status' => 'pending', 'configuration' => '{}', 'created_at' => $created, 'updated_at' => $created]);
        }
        $firstSet = DB::table('question_sets')->where('brand_id', $brand->id)->orderBy('version')->value('id');
        DB::table('vision_question_approvals')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $firstSet, 'approved_by' => $owner->id, 'providers' => json_encode(['gemini' => 'model-a', 'openai' => 'model-b']), 'active' => true, 'created_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details&month=2026-10')->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data')->assertJsonPath('plan.questions', 1)->assertJsonPath('plan.models', 2);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details&month=2026-13')->assertStatus(422);
        $this->withHeaders(['X-Brand-Id' => $other->id, 'X-Brand-Revision' => $revision])->getJson('/vision/runs/data?view=details&month=2026-10')->assertNotFound();
        Http::assertNothingSent();
    }
}
