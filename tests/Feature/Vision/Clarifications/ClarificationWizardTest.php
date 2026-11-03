<?php

namespace Tests\Feature\Vision\Clarifications;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClarificationWizardTest extends TenancyTestCase
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

    public function test_full_path_saves_answers_and_can_resume_by_brand(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $base = "/vision/runs/{$measurement}/clarify";
        $this->get("{$base}/mentions")->assertOk()->assertInertia(fn ($page) => $page->where('available', false));
        $this->confirm($owner, $brand, $measurement);
        $mention = $this->get("{$base}/mentions")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/mentions", ['item_key' => $mention['key'], 'decision' => 'yes', 'add_alias' => true])->assertRedirect();
        $presence = $this->get("{$base}/presence")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/presence", ['item_key' => $presence['key'], 'decision' => 'unknown',
            'profile_url' => 'https://2gis.ru/example'])->assertRedirect();
        $fact = $this->get("{$base}/facts")->assertOk()->inertiaProps('items.0');
        $this->post("{$base}/facts", ['item_key' => $fact['key'], 'decision' => 'outdated',
            'correction' => 'Работает с 2010 года'])->assertRedirect();
        $this->get("{$base}/done")->assertOk()->assertInertia(fn ($page) => $page->where('total', 3)->where('pending', 1));
        $this->get("{$base}/facts")->assertOk()->assertInertia(fn ($page) => $page->where('items.0.decision', 'outdated'));
        $this->assertDatabaseCount('vision_clarification_decisions', 3);
        $this->assertDatabaseHas('vision_clarification_decisions', ['brand_id' => $brand->id, 'decision' => 'outdated']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get("{$base}/facts")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_invalid_item_decision_and_correction_are_rejected(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        $base = "/vision/runs/{$measurement}/clarify/facts";
        $fact = $this->get($base)->assertOk()->inertiaProps('items.0');
        $this->post($base, ['item_key' => str_repeat('a', 64), 'decision' => 'correct'])->assertSessionHasErrors('item_key');
        $this->post($base, ['item_key' => $fact['key'], 'decision' => 'unknown'])->assertSessionHasErrors('decision');
        $this->post($base, ['item_key' => $fact['key'], 'decision' => 'incorrect'])->assertSessionHasErrors('correction');
        $this->assertDatabaseCount('vision_clarification_decisions', 0);
        Http::assertNothingSent();
    }

    public function test_member_without_write_right_cannot_change_decision(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        $measurement = $this->measurement($org, $brand);
        $this->confirm($owner, $brand, $measurement);
        $base = "/vision/runs/{$measurement}/clarify/facts";
        $fact = $this->get($base)->assertOk()->inertiaProps('items.0');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id]);
        $this->post($base, ['item_key' => $fact['key'], 'decision' => 'correct'])->assertForbidden();
        Http::assertNothingSent();
    }
}
