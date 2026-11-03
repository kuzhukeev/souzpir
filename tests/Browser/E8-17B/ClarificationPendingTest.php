<?php

namespace Tests\Feature\Vision\Clarifications;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Clarifications\ClarificationController;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClarificationPendingTest extends TenancyTestCase
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

    public function test_pending_count_tracks_unanswered_and_deferred_items(): void
    {
        [$owner, $org, $brand, , $member] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $member->id);
        $controller = app(ClarificationController::class);
        self::assertSame(3, $controller->pendingCount($tenant, $measurement));
        $base = "/vision/runs/{$measurement}/clarify";
        $item = $this->get("{$base}/mentions")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/mentions", ['item_key' => $item['key'], 'decision' => 'yes'])->assertRedirect();
        self::assertSame(2, $controller->pendingCount($tenant, $measurement));
        $item = $this->get("{$base}/presence")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/presence", ['item_key' => $item['key'], 'decision' => 'unknown'])->assertRedirect();
        self::assertSame(2, $controller->pendingCount($tenant, $measurement));
        $revision = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $headers = ['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $revision];
        $this->withHeaders($headers)->get('/vision/report/data')->assertOk()->assertJsonPath('clarifications_pending', 0);
    }
}
