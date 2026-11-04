<?php

namespace Tests\Feature\Vision\Measurements;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class MeasurementProgressE816HTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app()->instance(KnowledgeGate::class, new class implements KnowledgeGate
        {
            public function assertAllowed(TenantContext $tenant, string $measurementId, #[\SensitiveParameter] array $frozenProfile): void {}
        });
    }

    private function measurement($org, $brand): string
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $set = (string) Str::ulid();
        $id = (string) Str::ulid();
        $questions = [['id' => 'q1', 'text' => 'Вопрос']];
        $profile = ['synthetic' => true];
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'version' => 1, 'questions' => json_encode($questions), 'size' => 1, 'checksum' => CanonicalJson::hash($questions),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $id, 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => json_encode($profile),
            'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id,
            'brand_id' => $brand->id, 'measurement_id' => $id, 'question_n' => 1, 'provider' => 'fake',
            'requested_model' => 'fake-model', 'run_no' => 1, 'status' => 'ok', 'answer_text' => 'Ответ о бренде',
            'fact' => json_encode(['value' => 'Работает с 2009 года', 'identity_candidates' => ['Союз-Пир СПб'],
                'presence_candidates' => ['2gis.ru']]), 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function confirm($user, $brand, string $measurement): void
    {
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->post('/vision/runs/'.$measurement.'/confirm')->assertRedirect();
    }

    public function test_confirmed_clarifications_and_estimate_are_scoped_to_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $old = $this->measurement($org, $brand);
        $foreign = $this->measurement($org, $other);
        DB::table('measurements')->where('id', $old)->update(['created_at' => now()->subHours(2), 'started_at' => now()->subHours(2), 'finished_at' => now()->subMinutes(110)]);
        $this->confirm($owner, $brand, $old);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()
            ->assertJsonPath('data.0.pending_clarifications', 3)->assertDontSee($foreign);
        $base = '/vision/runs/'.$old.'/clarify/mentions';
        $item = $this->get($base)->assertOk()->inertiaProps('items.0');
        $this->post($base, ['item_key' => $item['key'], 'decision' => 'yes'])->assertRedirect();
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('data.0.pending_clarifications', 2);
        $row = (array) DB::table('measurements')->where('id', $old)->first();
        $new = (string) Str::ulid();
        $row['id'] = $new;
        $row['status'] = 'pending';
        $row['started_at'] = null;
        $row['finished_at'] = null;
        $row['created_at'] = now();
        DB::table('measurements')->insert($row);
        $answer = (array) DB::table('measurement_answers')->where('measurement_id', $old)->first();
        $answer['id'] = (string) Str::ulid();
        $answer['measurement_id'] = $new;
        $answer['status'] = 'pending';
        DB::table('measurement_answers')->insert($answer);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()
            ->assertJsonPath('data.0.estimated_minutes', 10)->assertJsonPath('data.0.pending_clarifications', null);
        $third = (string) Str::ulid();
        $row['id'] = $third;
        $row['created_at'] = now()->addMinute();
        DB::table('measurements')->insert($row);
        $answer['id'] = (string) Str::ulid();
        $answer['measurement_id'] = $third;
        $answer['requested_model'] = 'different';
        DB::table('measurement_answers')->insert($answer);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('data.0.estimated_minutes', null);
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertNotFound();
        Http::assertNothingSent();
    }
}
