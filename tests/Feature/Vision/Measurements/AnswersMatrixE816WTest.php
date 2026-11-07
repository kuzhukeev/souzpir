<?php

namespace Tests\Feature\Vision\Measurements;

use App\Ai\CanonicalJson;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AnswersMatrixE816WTest extends TenancyTestCase
{
    public function test_answers_data_includes_measurement_date(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $profile = ['site' => 'https://example.test'];
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $createdAt = now()->subDays(3)->startOfSecond();
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode([['id' => 'q1', 'text' => 'Где купить?', 'intent' => 'buy']]), 'size' => 1, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => json_encode($profile), 'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake', 'run_no' => 1, 'status' => 'ok', 'answer_text' => 'Пример бренда', 'created_at' => $createdAt, 'updated_at' => $createdAt]);
        $gate = Mockery::mock(KnowledgeGate::class);
        $gate->shouldReceive('assertAllowed')->andReturnNull();
        app()->instance(KnowledgeGate::class, $gate);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $page = $this->get('/vision/runs/'.$measurement.'/answers')->assertOk();
        $revision = $page->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->getJson('/vision/runs/'.$measurement.'/answers/data')->assertOk()
            ->assertJsonPath('measurement_number', 1)
            ->assertJsonPath('measurement_date', fn ($date) => is_string($date) && str_starts_with($date, $createdAt->format('Y-m-d')));
    }
}
