<?php

namespace Tests\Feature\Vision\Clarifications;

use App\Ai\CanonicalJson;
use App\Tenancy\TenantContext;
use App\Vision\Evidence\Contracts\KnowledgeGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClarificationAliasTest extends TenancyTestCase
{
    public function test_confirmed_mention_creates_a_new_profile_version(): void
    {
        config(['app.asset_url' => 'http://127.0.0.1']);
        URL::useOrigin('http://127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
        app()->instance(KnowledgeGate::class, new class implements KnowledgeGate
        {
            public function assertAllowed(TenantContext $tenant, string $measurementId, #[\SensitiveParameter] array $frozenProfile): void {}
        });
        [$owner, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/quiz/start')->assertRedirect();
        $quiz = DB::table('visibility_quizzes')->where('brand_id', $brand->id)->sole();
        $steps = [1 => ['brand_name' => 'Бренд', 'site' => 'https://example.com'], 2 => ['aliases' => ['Бренд', 'example.com']], 3 => ['category' => 'Тест', 'offering' => 'goods', 'audience' => 'b2c', 'buyer_phrase' => 'покупка полезного товара', 'category_forms' => ['nominative' => 'Тест', 'genitive_plural' => 'товаров']], 4 => ['competitors' => [['name' => 'Конкурент', 'site' => null]]], 5 => ['facts' => ['Факт']], 6 => ['presence' => []], 7 => ['questions' => ['Где купить?']]];
        $revision = 1;
        foreach ($steps as $step => $values) {
            $this->patch('/vision/quiz/step/'.$step, ['quiz_id' => $quiz->id, 'revision' => $revision++, 'values' => $values])->assertSessionHasNoErrors();
        }
        $this->post('/vision/quiz/submit', ['quiz_id' => $quiz->id, 'revision' => $revision])->assertSessionHasNoErrors();
        $set = (string) Str::ulid();
        $measurement = (string) Str::ulid();
        $questions = [['id' => 'q1', 'text' => 'Вопрос']];
        $profile = ['synthetic' => true];
        DB::table('question_sets')->insert(['id' => $set, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'version' => 1, 'questions' => json_encode($questions), 'size' => 1, 'checksum' => CanonicalJson::hash($questions), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurements')->insert(['id' => $measurement, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'question_set_id' => $set, 'status' => 'completed', 'configuration' => '{}', 'profile_snapshot' => json_encode($profile), 'profile_checksum' => CanonicalJson::hash($profile), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('measurement_answers')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'measurement_id' => $measurement, 'question_n' => 1, 'provider' => 'fake', 'requested_model' => 'fake-model', 'run_no' => 1, 'status' => 'ok', 'answer_text' => 'Союз-Пир СПб', 'fact' => json_encode(['identity_candidates' => ['Союз-Пир СПб']]), 'created_at' => now(), 'updated_at' => now()]);
        $rev = $this->get('/vision/runs')->assertOk()->inertiaProps('measurementBrandRevision');
        $this->withHeaders(['X-Brand-Id' => $brand->id, 'X-Brand-Revision' => $rev])->post('/vision/runs/'.$measurement.'/confirm')->assertRedirect();
        $path = '/vision/runs/'.$measurement.'/clarify/mentions';
        $item = $this->get($path)->assertOk()->assertInertia(fn ($page) => $page->where('alias_available', true))->inertiaProps('items.0');
        $this->post($path, ['item_key' => $item['key'], 'decision' => 'yes', 'apply_alias' => true])->assertRedirect()->assertSessionHasNoErrors();
        $profiles = DB::table('brand_profiles')->where('brand_id', $brand->id)->orderBy('version')->get();
        self::assertCount(2, $profiles);
        self::assertContains('Союз-Пир СПб', json_decode($profiles[1]->answers, true)['step2']['aliases']);
        self::assertSame(0, DB::table('brand_profiles')->where('brand_id', $other->id)->count());
        Http::assertNothingSent();
    }
}
