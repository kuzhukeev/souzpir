<?php

namespace Tests\Feature\Vision\Measurements;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SnapshotConfirmationTest extends TenancyTestCase
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

    private function measurement($organization, $brand, string $status = 'completed', string $answerStatus = 'ok'): string
    {
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $questions = [['id' => 'q1', 'text' => 'Вопрос']];
        $profile = ['synthetic' => true];
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'version' => DB::table('question_sets')->where('brand_id', $brand->id)->count() + 1, 'questions' => json_encode($questions), 'size' => 1, 'checksum' => CanonicalJson::hash($questions), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $organization->id, 'brand_id' => $brand->id,
            'question_set_id' => $set, 'status' => $status, 'configuration' => '{}', 'profile_snapshot' => json_encode($profile),
            'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'brand_id' => $brand->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake',
            'requested_model' => 'fake-model', 'run_no' => 1, 'status' => $answerStatus, 'answer_text' => 'Сохранённый ответ',
            'fact' => json_encode(['value' => 'сохранённый факт']), 'created_at' => now(), 'updated_at' => now()]);

        return $measurement;
    }

    private function enable($organization, $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization->id,
            'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function revision($user, $brand): string
    {
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);

        return $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
    }

    public function test_confirm_copies_saved_answers_once_and_marks_only_that_measurement(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->enable($organization, $brand);
        $measurement = $this->measurement($organization, $brand);
        $revision = $this->revision($owner, $brand);
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->post('/vision/runs/'.$measurement.'/confirm')->assertRedirect()->assertSessionHas('status', 'Снимок подтверждён.');
        $this->withHeaders($headers)->post('/vision/runs/'.$measurement.'/confirm')->assertRedirect()->assertSessionHas('status', 'Снимок уже подтверждён.');
        $this->assertDatabaseCount('snapshot_confirmations', 1);
        $this->assertDatabaseCount('answer_facts', 1);
        $this->assertDatabaseHas('answer_facts', ['brand_id' => $brand->id, 'answer_text' => 'Сохранённый ответ']);
        $this->withHeaders($headers)->getJson('/vision/runs/data?view=details')->assertOk()->assertJsonPath('data.0.confirmed', true);
        self::assertSame(1, DB::table('audit_logs')->where('action', 'vision.measurement.confirmed')->count());
        Http::assertNothingSent();
    }

    public function test_unfinished_invalid_and_foreign_brand_are_rejected(): void
    {
        [$owner, $organization, $brand, $other] = $this->cabinet();
        $this->enable($organization, $brand);
        $this->enable($organization, $other);
        $foreign = $this->measurement($organization, $other);
        $unfinished = $this->measurement($organization, $brand, 'running', 'pending');
        $missingAnswer = $this->measurement($organization, $brand, 'completed', 'pending');
        $revision = $this->revision($owner, $brand);
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->postJson('/vision/runs/'.$unfinished.'/confirm')->assertUnprocessable();
        $this->withHeaders($headers)->postJson('/vision/runs/'.$missingAnswer.'/confirm')->assertUnprocessable();
        $this->withHeaders($headers)->postJson('/vision/runs/'.$foreign.'/confirm')->assertNotFound();
        $this->withHeaders($headers)->postJson('/vision/runs/not-a-ulid/confirm')->assertNotFound();
        $this->assertDatabaseCount('snapshot_confirmations', 0);
    }

    public function test_viewer_cannot_confirm(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->enable($organization, $brand);
        $measurement = $this->measurement($organization, $brand);
        [$viewer] = $this->member($organization, 'viewer', [$brand->id]);
        $revision = $this->revision($viewer, $brand);
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->postJson('/vision/runs/'.$measurement.'/confirm')->assertForbidden();
        $this->assertDatabaseCount('snapshot_confirmations', 0);
    }

    public function test_revoked_knowledge_cannot_be_confirmed(): void
    {
        [$owner, $organization, $brand] = $this->cabinet();
        $this->enable($organization, $brand);
        $measurement = $this->measurement($organization, $brand);
        app()->instance(KnowledgeGate::class, new class implements KnowledgeGate
        {
            public function assertAllowed(TenantContext $tenant, string $measurementId, #[\SensitiveParameter] array $frozenProfile): void
            {
                throw new \LogicException('revoked');
            }
        });
        $revision = $this->revision($owner, $brand);
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision])
            ->postJson('/vision/runs/'.$measurement.'/confirm')->assertUnprocessable();
        $this->assertDatabaseCount('snapshot_confirmations', 0);
        $this->assertDatabaseCount('answer_facts', 0);
    }
}
