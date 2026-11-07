<?php

namespace Tests\Feature\Vision\Measurements;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProgressE816LTest extends TenancyTestCase
{
    public function test_progress_is_scoped_and_requires_current_brand_revision(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $set = (string) Str::ulid();
        $id = (string) Str::ulid();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => '["Question"]', 'size' => 1, 'checksum' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'pending', 'configuration' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/vision/runs/'.$id.'/progress/data')->assertUnauthorized();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs/'.$id.'/progress')->assertOk()->assertInertia(fn ($page) => $page->component('Vision/Measurements/Progress'))->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->getJson('/vision/runs/'.$id.'/progress/data')->assertStatus(409);
        $this->withHeaders($headers)->getJson('/vision/runs/'.$id.'/progress/data')->assertOk()->assertJsonPath('measurement.status', 'pending')->assertJsonPath('measurement.estimated_minutes', null)->assertJsonMissingPath('measurement.configuration');
        $this->withHeaders($headers)->getJson('/vision/runs/'.(string) Str::ulid().'/progress/data')->assertNotFound();
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/runs/'.$id.'/progress')->assertNotFound();
        $this->withHeaders($headers)->getJson('/vision/runs/'.$id.'/progress/data')->assertNotFound();
    }
}
