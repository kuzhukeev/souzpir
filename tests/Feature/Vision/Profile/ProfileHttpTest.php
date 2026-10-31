<?php

namespace Tests\Feature\Vision\Profile;

use App\Http\Middleware\HandleInertiaRequests;
use App\Shell\Audit\Contracts\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProfileHttpTest extends TenancyTestCase
{
    private function privateGet(string $path): TestResponse
    {
        $this->get($path)->assertRedirect('/vision/private-access');
        $page = $this->get('/vision/private-access')->assertOk();
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $page->getContent(), $matches));
        $this->post('/vision/private-access', ['proof' => hash('sha256', hex2bin($matches[1]))])->assertRedirect($path);

        return $this->get($path);
    }

    private function fixtureOtherBrandId(string $organizationId, string $brandId): string
    {
        return DB::table('brands')->where('organization_id', $organizationId)->where('id', '<>', $brandId)->value('id');
    }

    private function fixture(): array
    {
        [$user, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $entry) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $entry->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Http::preventStrayRequests();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);

        return [$user, $org, $brand, $other];
    }

    public function test_real_http_questionnaire_submit_and_profile_edit_preserve_history_and_audit(): void
    {
        [$user, $org, $brand] = $this->fixture();
        $this->get('/vision')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Start', false)->where('current', null));
        $this->post('/vision/quiz/start')->assertRedirect('/vision/quiz/1');
        $quiz = DB::table('visibility_quizzes')->where('brand_id', $brand->id)->sole();
        $this->privateGet('/vision/quiz/1')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Quiz', false)->where('quiz.id', $quiz->id)->where('quiz.revision', 1));
        $steps = [1 => ['brand_name' => 'Проверочный бренд', 'site' => 'https://example.com'], 2 => ['aliases' => ['Бренд', 'example.com']], 3 => ['category' => 'Тест', 'offering' => 'goods', 'audience' => 'b2c', 'buyer_phrase' => 'покупка полезного товара', 'category_forms' => ['nominative' => 'Тест', 'genitive_plural' => 'товаров']], 4 => ['competitors' => [['name' => 'Конкурент', 'site' => null]]], 5 => ['facts' => ['Тестовый факт']], 6 => ['presence' => []], 7 => ['questions' => ['Где купить?']]];
        $revision = 1;
        foreach ($steps as $step => $values) {
            $this->patch('/vision/quiz/step/'.$step, ['quiz_id' => $quiz->id, 'revision' => $revision++, 'values' => $values])->assertRedirect('/vision/quiz/'.($step + 1))->assertSessionHasNoErrors();
        }
        $this->post('/vision/quiz/submit', ['quiz_id' => $quiz->id, 'revision' => $revision])->assertRedirect('/vision/profile')->assertSessionHasNoErrors();
        $profile = DB::table('brand_profiles')->where('brand_id', $brand->id)->sole();
        $this->post('/vision/quiz/start')->assertRedirect('/vision/profile');
        self::assertSame(1, DB::table('visibility_quizzes')->where('brand_id', $brand->id)->count());
        $this->privateGet('/vision/profile')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Show', false)->where('profile.id', $profile->id)->where('profile.answers.step1.brand_name', 'Проверочный бренд'));
        $this->patch('/vision/profile/step/2', ['profile_id' => $profile->id, 'values' => ['aliases' => ['Новое имя', 'example.com']]])->assertRedirect('/vision/profile')->assertSessionHasNoErrors();
        self::assertSame(2, DB::table('brand_profiles')->where('brand_id', $brand->id)->count());
        $this->privateGet('/vision/profile')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Show', false)->where('is_latest', true)->where('previous_profile.id', $profile->id)->where('previous_profile.answers.step2.aliases.0', 'Бренд')->where('profile.answers.step2.aliases.0', 'Новое имя'));
        self::assertSame(['Бренд', 'example.com'], json_decode($profile->answers, true)['step2']['aliases']);
        $this->privateGet('/vision/profile/'.$profile->id)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Show', false)->where('is_latest', false)->where('profile.answers.step2.aliases.0', 'Бренд'));
        $this->patchJson('/vision/profile/step/2', ['profile_id' => $profile->id, 'values' => ['aliases' => ['Потеря данных']]])->assertStatus(409);
        $this->withSession(['brand_id' => $this->fixtureOtherBrandId($org->id, $brand->id)])->get('/vision/profile/'.$profile->id)->assertRedirect('/vision/private-access');
        foreach (['vision.quiz.created', 'vision.quiz.step_saved', 'vision.quiz.submitted', 'vision.profile.updated'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['organization_id' => $org->id, 'action' => $action]);
        }
        self::assertStringNotContainsString('Тестовый факт', json_encode(DB::table('audit_logs')->get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        Http::assertNothingSent();
    }

    public function test_incomplete_draft_is_saved_but_cannot_be_submitted(): void
    {
        [, , $brand] = $this->fixture();
        $this->post('/vision/quiz/start')->assertRedirect();
        $id = DB::table('visibility_quizzes')->where('brand_id', $brand->id)->value('id');
        foreach (range(1, 7) as $step) {
            $value = $step === 1 ? ['brand_name' => 'Synthetic'] : [];
            $this->patch('/vision/quiz/step/'.$step, ['quiz_id' => $id, 'revision' => $step, 'values' => $value])->assertRedirect();
        }
        $this->postJson('/vision/quiz/submit', ['quiz_id' => $id, 'revision' => 8])->assertStatus(422);
        $this->assertDatabaseHas('visibility_quizzes', ['id' => $id, 'status' => 'draft', 'revision' => 8]);
        self::assertSame(0, DB::table('brand_profiles')->count());
    }

    public function test_conflicts_limits_and_cross_brand_are_rejected(): void
    {
        [, , $brand, $other] = $this->fixture();
        $this->post('/vision/quiz/start')->assertRedirect();
        $id = DB::table('visibility_quizzes')->where('brand_id', $brand->id)->value('id');
        $this->patchJson('/vision/quiz/step/4', ['quiz_id' => $id, 'revision' => 1, 'values' => ['competitors' => array_fill(0, 11, ['name' => 'x'])]])->assertStatus(422);
        $this->patchJson('/vision/quiz/step/5', ['quiz_id' => $id, 'revision' => 1, 'values' => ['facts' => []]])->assertOk()->assertJson(['ok' => true, 'redirect' => '/vision/quiz/6']);
        $this->patchJson('/vision/quiz/step/5', ['quiz_id' => $id, 'revision' => 1, 'values' => ['facts' => ['x']]])->assertStatus(409);
        $this->withSession(['brand_id' => $other->id])->patchJson('/vision/quiz/step/5', ['quiz_id' => $id, 'revision' => 2, 'values' => ['facts' => ['x']]])->assertStatus(403);
        self::assertSame(2, DB::table('visibility_quizzes')->where('id', $id)->value('revision'));
    }

    public function test_viewer_cannot_mutate_and_inaccessible_brand_header_is_rejected(): void
    {
        [, $org, $brand, $other] = $this->fixture();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/quiz/start')->assertForbidden();
        $this->withHeader('X-Brand-Id', $other->id)->get('/vision')->assertNotFound();
        self::assertSame(0, DB::table('visibility_quizzes')->count());
    }

    public function test_direct_private_get_requires_fresh_crypto_proof_and_never_exposes_answers_on_neutral_page(): void
    {
        $this->fixture();
        $this->post('/vision/quiz/start')->assertRedirect();
        $id = DB::table('visibility_quizzes')->value('id');
        $this->patch('/vision/quiz/step/1', ['quiz_id' => $id, 'revision' => 1, 'values' => ['brand_name' => 'PRIVATE_BROWSER_TEST_MARKER']])->assertRedirect();
        $this->get('/vision/quiz/1')->assertRedirect('/vision/private-access');
        $neutral = $this->get('/vision/private-access')->assertOk();
        self::assertStringNotContainsString('PRIVATE_BROWSER_TEST_MARKER', $neutral->getContent());
        self::assertStringContainsString('crypto?.subtle', $neutral->getContent());
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $neutral->getContent(), $matches));
        $this->post('/vision/private-access', ['proof' => str_repeat('0', 64)])->assertForbidden();
        $this->get('/vision/quiz/1')->assertRedirect('/vision/private-access');
        $neutral = $this->get('/vision/private-access')->assertOk();
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $neutral->getContent(), $matches));
        $proof = hash('sha256', hex2bin($matches[1]));
        $this->post('/vision/private-access', ['proof' => $proof])->assertRedirect('/vision/quiz/1');
        $this->get('/vision/quiz/1')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Quiz', false)->where('quiz.answers.step1.brand_name', 'PRIVATE_BROWSER_TEST_MARKER'));
        $this->get('/vision/quiz/1')->assertRedirect('/vision/private-access');
        $this->post('/vision/private-access', ['proof' => $proof])->assertForbidden();
        $version = app(HandleInertiaRequests::class)->version(Request::create('/vision/quiz/1'));
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->get('/vision/quiz/1')->assertStatus(409)->assertHeader('X-Inertia-Location', '/vision/private-access');
    }

    public function test_private_pages_and_mutations_require_secure_context(): void
    {
        $this->fixture();
        $this->post('/vision/quiz/start')->assertRedirect();
        $id = DB::table('visibility_quizzes')->value('id');
        $this->patch('/vision/quiz/step/1', ['quiz_id' => $id, 'revision' => 1, 'values' => ['brand_name' => 'SYNTHETIC_PRIVATE_MARKER']])->assertRedirect();
        $response = $this->get('http://souzpir.local/vision/quiz/1');
        $response->assertForbidden();
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE_MARKER', $response->getContent());
        $this->patchJson('http://souzpir.local/vision/quiz/step/1', ['quiz_id' => $id, 'revision' => 2, 'values' => ['brand_name' => 'Changed']])->assertForbidden();
        self::assertSame(2, DB::table('visibility_quizzes')->where('id', $id)->value('revision'));
        $this->get('http://127.0.0.1:8248/vision/quiz/1')->assertRedirect('/vision/private-access');
    }

    public function test_local_named_host_only_uses_unencrypted_history_for_a_loopback_peer(): void
    {
        $this->fixture();
        $this->post('/vision/quiz/start')->assertRedirect();
        $id = DB::table('visibility_quizzes')->value('id');
        $this->patch('/vision/quiz/step/1', ['quiz_id' => $id, 'revision' => 1, 'values' => ['brand_name' => 'LOCAL_ONLY_MARKER']])->assertRedirect();
        $originalEnvironment = app()->environment();
        app()->instance('env', 'local');

        try {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
            $this->get('http://souzpir.local/vision/quiz/1')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertInertia(fn (Assert $page) => $page->component('Vision/Profile/Quiz', false)->where('quiz.answers.step1.brand_name', 'LOCAL_ONLY_MARKER'));
            $csrf = str_repeat('c', 40);
            $this->withSession(['_token' => $csrf])->withHeader('X-CSRF-TOKEN', $csrf)
                ->patchJson('http://souzpir.local/vision/quiz/step/1', ['quiz_id' => $id, 'revision' => 2, 'values' => ['brand_name' => 'Local change']])->assertOk();
            $this->get('http://other.local/vision/quiz/1')->assertForbidden();

            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55']);
            $this->get('http://souzpir.local/vision/quiz/1')->assertForbidden();
            $this->withHeader('X-Forwarded-For', '127.0.0.1')->get('http://localhost/vision/quiz/1')->assertForbidden();
            $this->get('http://127.0.0.1/vision/quiz/1')->assertForbidden();

            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
            $this->get('https://souzpir.local/vision/quiz/1')->assertRedirect('/vision/private-access');
            $this->get('http://localhost/vision/quiz/1')->assertRedirect('/vision/private-access');
            app()->instance('env', 'production');
            $this->get('http://souzpir.local/vision/quiz/1')->assertForbidden();
        } finally {
            app()->instance('env', $originalEnvironment);
        }
    }

    public function test_unexpected_exception_and_private_marker_do_not_reach_reporter(): void
    {
        $this->fixture();
        $marker = 'PRIVATE_SYNTHETIC_MARKER_8F251';
        Log::spy();
        $this->post('/vision/quiz/start')->assertRedirect();
        $id = DB::table('visibility_quizzes')->value('id');
        $this->mock(AuditWriter::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException($marker));
        $response = $this->patchJson('/vision/quiz/step/1', ['quiz_id' => $id, 'revision' => 1, 'values' => ['brand_name' => $marker]]);
        $response->assertStatus(403);
        self::assertStringNotContainsString($marker, $response->getContent());
        Log::shouldNotHaveReceived('error');
    }
}
