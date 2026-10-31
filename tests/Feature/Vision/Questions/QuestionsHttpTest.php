<?php

namespace Tests\Feature\Vision\Questions;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Shell\Audit\Contracts\AuditWriter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Vision\Core\MeasurementRunner;
use App\Vision\KnowledgeGrants\GrantRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class QuestionsHttpTest extends TenancyTestCase
{
    private function fixture(): array
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $b) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $b->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Http::preventStrayRequests();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/quiz/start')->assertRedirect();
        $id = DB::table('visibility_quizzes')->where('brand_id', $brand->id)->value('id');
        $steps = [1 => ['site' => 'https://alpha.example.com', 'brand_name' => 'Бренд Пегас', 'legal_name' => 'Общество Звезда'], 2 => ['aliases' => ['Пегас', 'alpha.example.com'], 'former_names' => ['Вега']], 3 => ['category' => 'Услуги', 'offering' => 'services', 'audience' => 'b2c', 'buyer_phrase' => 'поиск услуг рядом', 'category_forms' => ['nominative' => 'Услуги', 'genitive_plural' => 'услуг']], 4 => ['competitors' => []], 5 => ['facts' => []], 6 => ['presence' => []], 7 => ['questions' => ['Где найти качественные услуги в регионе?']]];
        foreach ($steps as $step => $values) {
            $this->patch('/vision/quiz/step/'.$step, ['quiz_id' => $id, 'revision' => $step, 'values' => $values])->assertRedirect();
        }
        $this->post('/vision/quiz/submit', ['quiz_id' => $id, 'revision' => 8])->assertRedirect('/vision/profile');

        return [$owner, $org, $brand, $other];
    }

    public function test_manual_candidates_and_immutable_approvals_accept_12_20_30_without_network(): void
    {
        [, $org, $brand] = $this->fixture();
        $ids = [];
        for ($i = 1; $i <= 30; $i++) {
            $response = $this->postJson('/vision/questions/candidates', ['text' => "Где найти подходящие услуги номер $i?", 'intent' => 'choose'])->assertStatus(201);
            $ids[] = $response->json('id');
        }
        self::assertSame('blocked', DB::table('question_generation_batches')->value('status'));
        self::assertSame(1, DB::table('vision_question_manual_batches')->count());
        $model = ['chatgpt' => 'openai/gpt-6.1-sol'];
        foreach ([12, 20, 30] as $size) {
            $this->postJson('/vision/questions/approve', ['candidate_ids' => array_slice($ids, 0, $size), 'models' => $model, 'frequency' => null])->assertOk()->assertJsonPath('ok', true);
        }
        self::assertSame([12, 20, 30], DB::table('question_sets')->where('brand_id', $brand->id)->orderBy('version')->pluck('size')->all());
        self::assertSame(1, DB::table('vision_question_approvals')->where('brand_id', $brand->id)->where('active', true)->count());
        $response = $this->getJson('/vision/questions/data')->assertOk()->assertJsonPath('active_set.size', 30)->assertJsonCount(3, 'history')->assertJsonCount(30, 'candidates');
        self::assertNotEmpty($response->json('active_set.checksum'));
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => 'vision.questions.approved']);
        self::assertSame(0, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    public function test_approved_set_can_be_used_by_fake_measurement_runtime_with_real_grant(): void
    {
        [$owner, $org, $brand] = $this->fixture();
        $id = $this->postJson('/vision/questions/candidates', ['text' => 'Где найти качественные услуги рядом?', 'intent' => 'choose'])->assertStatus(201)->json('id');
        $set = $this->postJson('/vision/questions/approve', ['candidate_ids' => [$id], 'models' => ['gemini' => 'google/gemini-2.5-flash'], 'frequency' => null])->assertOk()->json('id');
        self::assertSame($set, DB::table('vision_question_approvals')->where('brand_id', $brand->id)->where('active', true)->value('question_set_id'));
        $member = DB::table('memberships')->where('organization_id', $org->id)->where('user_id', $owner->id)->value('id');
        $ctx = new TenantContext($org->id, $brand->id, $owner->id, $member);
        app(TenantContextRunner::class)->run($ctx, function () use ($ctx, $brand, $set): void {
            $source = ['conditions' => [], 'facts' => [['id' => 'assortment', 'facts' => ['Published safe fact']]], 'triggers' => [], 'guard_rules' => [], 'policy' => [], 'intents' => [], 'escalation' => [], 'persona' => [], 'fewshot' => [], 'closed_topics' => []];
            $repository = app(DatabaseKnowledgeRepository::class);
            $draft = $repository->createDraft($ctx, $source);
            $release = $repository->publish($ctx, $draft['id'], 1);
            $fact = DB::table('kn_facts as f')->join('kn_fact_groups as g', 'g.id', '=', 'f.group_id')->where('g.release_id', $release->releaseId())->value('f.logical_id');
            DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_public' => ['any']]], JSON_THROW_ON_ERROR)]);
            app(GrantRegistry::class)->issue($ctx, $release->releaseId(), [$fact]);
            config(['runtime.ai_driver' => 'fake', 'features.E8-01.defaults.parser' => ['model' => 'google/gemini-2.5-flash', 'temperature' => 0.0, 'max_tokens' => 1200]]);
            $measurement = app(MeasurementRunner::class)->start($set, ['gemini' => 'google/gemini-2.5-flash'], 1);
            $this->assertDatabaseHas('measurements', ['id' => $measurement, 'question_set_id' => $set, 'status' => 'pending']);
            self::assertSame(1, DB::table('measurement_answers')->where('measurement_id', $measurement)->count());
            self::assertSame(0, DB::table('ai_calls')->count());
        });
        Http::assertNothingSent();
    }

    public function test_explicit_profile_import_is_idempotent_and_never_starts_generation(): void
    {
        $this->fixture();
        $this->postJson('/vision/questions/import-profile', ['intent' => 'choose'])->assertOk()->assertJson(['added' => 1, 'skipped' => 0]);
        $this->postJson('/vision/questions/import-profile', ['intent' => 'price'])->assertOk()->assertJson(['added' => 0, 'skipped' => 1]);
        $this->getJson('/vision/questions/data')->assertOk()->assertJsonPath('candidates.0.origin', 'questionnaire')->assertJsonPath('candidates.0.intent', 'choose');
        $imported = DB::table('question_candidates')->value('id');
        $this->getJson('/vision/questions/data')->assertJsonPath('candidates.0.can_delete', false);
        $this->deleteJson('/vision/questions/candidates/'.$imported)->assertForbidden();
        $this->assertDatabaseHas('vision_question_manual_batches', ['origin' => 'questionnaire']);
        $this->assertDatabaseHas('question_generation_batches', ['status' => 'blocked']);
        self::assertSame(0, DB::table('ai_calls')->count());
        Http::assertNothingSent();
    }

    public function test_manual_deletion_is_limited_to_its_author_and_disabled_questions_cannot_be_selected(): void
    {
        [$owner, $org, $brand] = $this->fixture();
        $ownerId = $this->postJson('/vision/questions/candidates', ['text' => 'Как найти надёжных поставщиков в городе?', 'intent' => 'choose'])->assertStatus(201)->json('id');
        [$editor] = $this->member($org, 'member', [$brand->id]);
        $this->actingAs($editor)->withSession(['brand_id' => $brand->id]);
        $this->deleteJson('/vision/questions/candidates/'.$ownerId)->assertForbidden();
        $editorId = $this->postJson('/vision/questions/candidates', ['text' => 'Кто оказывает услуги с хорошими отзывами?', 'intent' => 'trust'])->assertStatus(201)->json('id');
        $this->patchJson('/vision/questions/candidates/'.$editorId, ['status' => 'disabled'])->assertOk();
        $this->patchJson('/vision/questions/candidates/'.$editorId, ['selected' => true])->assertStatus(422);
        $this->deleteJson('/vision/questions/candidates/'.$editorId)->assertOk();
        $this->assertDatabaseHas('question_candidates', ['id' => $ownerId]);
        $this->assertDatabaseMissing('question_candidates', ['id' => $editorId]);
    }

    public function test_copy_active_reuses_existing_candidates_and_preserves_selection_and_disabled_state(): void
    {
        $this->fixture();
        $id = $this->postJson('/vision/questions/candidates', ['text' => 'Где найти качественные услуги рядом?', 'intent' => 'choose'])->assertStatus(201)->json('id');
        $this->postJson('/vision/questions/approve', ['candidate_ids' => [$id], 'models' => ['chatgpt' => 'openai/gpt-6.1-sol'], 'frequency' => null])->assertOk();
        $this->patchJson('/vision/questions/candidates/'.$id, ['selected' => false])->assertOk();
        $this->postJson('/vision/questions/copy-active')->assertOk()->assertJson(['added' => 0, 'skipped' => 1]);
        $this->assertDatabaseHas('question_candidates', ['id' => $id, 'selected' => false]);
        self::assertSame(1, DB::table('question_candidates')->count());
        self::assertSame(0, DB::table('vision_question_manual_batches')->where('origin', 'active_set')->count());
        $this->patchJson('/vision/questions/candidates/'.$id, ['status' => 'disabled'])->assertOk();
        $this->postJson('/vision/questions/copy-active')->assertOk()->assertJson(['added' => 0, 'skipped' => 1]);
        $this->assertDatabaseHas('question_candidates', ['id' => $id, 'status' => 'disabled', 'selected' => false]);
    }

    public function test_active_set_copy_after_profile_change_is_idempotent_and_preserves_source_snapshot(): void
    {
        [, , $brand] = $this->fixture();
        $id = $this->postJson('/vision/questions/candidates', ['text' => 'Где найти качественные услуги рядом?', 'intent' => 'choose'])->assertStatus(201)->json('id');
        $set = $this->postJson('/vision/questions/approve', ['candidate_ids' => [$id], 'models' => ['chatgpt' => 'openai/gpt-6.1-sol'], 'frequency' => null])->assertOk()->json('id');
        $before = DB::table('question_sets')->where('id', $set)->value('questions');
        $profile = DB::table('brand_profiles')->where('brand_id', $brand->id)->orderByDesc('version')->value('id');
        $this->patchJson('/vision/profile/step/5', ['profile_id' => $profile, 'values' => ['facts' => ['Новый пользовательский факт']]])->assertOk();
        $this->getJson('/vision/questions/data')->assertOk()->assertJsonCount(0, 'candidates')->assertJsonPath('active_set.id', $set);
        $this->postJson('/vision/questions/copy-active')->assertOk()->assertJson(['added' => 1, 'skipped' => 0]);
        $this->postJson('/vision/questions/copy-active')->assertOk()->assertJson(['added' => 0, 'skipped' => 1]);
        $this->getJson('/vision/questions/data')->assertOk()->assertJsonPath('candidates.0.origin', 'active_set')->assertJsonPath('candidates.0.selected', true)->assertJsonPath('candidates.0.can_delete', false);
        $this->assertDatabaseHas('vision_question_copy_entries', ['source_set_id' => $set, 'source_question_id' => $id]);
        self::assertSame($before, DB::table('question_sets')->where('id', $set)->value('questions'));
        self::assertSame(1, DB::table('vision_question_approvals')->where('active', true)->count());
        Http::assertNothingSent();
    }

    public function test_brand_domain_stem_legal_and_former_names_and_ambiguous_alias_are_checked(): void
    {
        [, , $brand] = $this->fixture();
        foreach (['Где найти компанию alpha в регионе?', 'Где найти Общество Звезда в регионе?', 'Где заказать услуги Вега в регионе?'] as $text) {
            $this->postJson('/vision/questions/candidates', ['text' => $text, 'intent' => 'choose'])->assertStatus(422);
        }
        $profile = DB::table('brand_profiles')->where('brand_id', $brand->id)->orderByDesc('version')->value('id');
        $this->patchJson('/vision/profile/step/2', ['profile_id' => $profile, 'values' => ['aliases' => ['Пегас', 'alpha.example.com', 'Торт'], 'former_names' => ['Вега']]])->assertOk();
        $this->postJson('/vision/questions/candidates', ['text' => 'Где найти компанию Торт в регионе?', 'intent' => 'choose'])->assertStatus(422);
        $this->postJson('/vision/questions/candidates', ['text' => 'Где заказать торт на праздник?', 'intent' => 'choose'])->assertStatus(201);
        $current = DB::table('brand_profiles')->where('brand_id', $brand->id)->orderByDesc('version')->value('id');
        $this->patchJson('/vision/profile/step/2', ['profile_id' => $current, 'values' => ['aliases' => ['Пегас', 'alpha.example.com', 'Foo  Bar']]])->assertOk();
        $this->postJson('/vision/questions/candidates', ['text' => 'Где работает компания Foo Bar сегодня?', 'intent' => 'choose'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_sensitive_api_requires_secure_origin(): void
    {
        $this->fixture();
        $this->getJson('http://souzpir.local/vision/questions/data')->assertForbidden()->assertJsonMissingPath('candidates');
        $this->postJson('http://souzpir.local/vision/questions/candidates', ['text' => 'Где найти качественные услуги рядом?', 'intent' => 'choose'])->assertForbidden();
        self::assertSame(0, DB::table('question_candidates')->count());
    }

    public function test_blocked_nonmanual_batch_cannot_be_approved_and_scheduling_is_not_implied(): void
    {
        [$user, $org, $brand] = $this->fixture();
        $profile = DB::table('brand_profiles')->where('brand_id', $brand->id)->sole();
        $batchId = (string) Str::ulid();
        DB::table('question_generation_batches')->insert(['id' => $batchId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'status' => 'blocked', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $candidateId = (string) Str::ulid();
        DB::table('question_candidates')->insert(['id' => $candidateId, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'quiz_id' => $profile->quiz_id, 'profile_id' => $profile->id, 'batch_id' => $batchId, 'text' => 'Где найти надёжные услуги поблизости?', 'intent' => 'choose', 'status' => 'proposed', 'selected' => false, 'created_at' => now(), 'updated_at' => now()]);
        $models = ['chatgpt' => 'openai/gpt-6.1-sol'];
        $this->getJson('/vision/questions/data')->assertOk()->assertJsonCount(0, 'candidates');
        $this->postJson('/vision/questions/approve', ['candidate_ids' => [$candidateId], 'models' => $models, 'frequency' => null])->assertStatus(422);
        $manual = $this->postJson('/vision/questions/candidates', ['text' => 'Где найти надёжные услуги поблизости?', 'intent' => 'choose'])->assertStatus(201)->json('id');
        $this->postJson('/vision/questions/approve', ['candidate_ids' => [$manual], 'models' => $models, 'frequency' => 'weekly'])->assertStatus(422);
        self::assertSame(0, DB::table('question_sets')->count());
    }

    public function test_unexpected_failure_does_not_log_private_text_or_commit_partial_candidate(): void
    {
        $this->fixture();
        $marker = 'PRIVATE_SYNTHETIC_QUESTION_MARKER_9031';
        Log::spy();
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException($marker));
        $response = $this->postJson('/vision/questions/candidates', ['text' => $marker.' 1234567890', 'intent' => 'choose'])->assertForbidden();
        self::assertStringNotContainsString($marker, $response->getContent());
        self::assertSame(0, DB::table('question_candidates')->count());
        self::assertSame(0, DB::table('vision_question_manual_batches')->count());
        Log::shouldNotHaveReceived('error');
    }

    public function test_limits_planned_models_brand_words_and_role_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->fixture();
        $this->postJson('/vision/questions/candidates', ['text' => 'Почему Пегас так часто рекомендуют?', 'intent' => 'choose'])->assertStatus(422);
        $created = $this->postJson('/vision/questions/candidates', ['text' => 'Где найти качественные услуги рядом?', 'intent' => 'choose'])->assertStatus(201)->json('id');
        $this->postJson('/vision/questions/approve', ['candidate_ids' => [$created], 'models' => ['yandexgpt' => 'yandexgpt'], 'frequency' => null])->assertStatus(422);
        $plan = (string) Str::ulid();
        DB::table('plans')->insert(['id' => $plan, 'code' => 'fixture', 'name' => 'Fixture', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_plan')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'plan_id' => $plan, 'starts_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('plan_limits')->insert(['id' => (string) Str::ulid(), 'plan_id' => $plan, 'key' => 'vision.questions', 'value' => '0', 'unit' => '', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/vision/questions/approve', ['candidate_ids' => [$created], 'models' => ['chatgpt' => 'openai/gpt-6.1-sol'], 'frequency' => null])->assertStatus(422);
        $this->withSession(['brand_id' => $other->id])->deleteJson('/vision/questions/candidates/'.$created)->assertStatus(403);
        $this->withSession(['brand_id' => $brand->id]);
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->getJson('/vision/questions/data')->assertOk()->assertJsonPath('can_edit', false)->assertJsonCount(0, 'candidates');
        $this->postJson('/vision/questions/candidates', ['text' => 'Где найти качественные услуги рядом?', 'intent' => 'choose'])->assertForbidden();
        Http::assertNothingSent();
    }
}
