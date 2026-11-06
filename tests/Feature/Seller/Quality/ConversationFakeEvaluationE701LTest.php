<?php

namespace Tests\Feature\Seller\Quality;

use App\Ai\CanonicalJson;
use App\Seller\Evaluation\Judge;
use App\Seller\Evaluation\ScenarioFallbackJudge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ConversationFakeEvaluationE701LTest extends TenancyTestCase
{
    public function test_fake_evaluation_is_scoped_idempotent_and_excluded_from_quality(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $dialog = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['client' => 'Здравствуйте', 'agent' => 'Добрый день'] as $role => $text) {
            DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'role' => $role, 'text' => $text, 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $response = $this->post('/seller/quality/dialogs/'.$dialog.'/evaluate');
        $evaluation = DB::table('seller_evaluations')->where('conversation_id', $dialog)->first();
        self::assertNotNull($evaluation);
        $response->assertRedirect('/seller/evaluations/'.$evaluation->id);
        self::assertSame(ScenarioFallbackJudge::COMMENT, $evaluation->comment);
        $this->get('/seller/evaluations/'.$evaluation->id)->assertOk()->assertInertia(fn ($page) => $page->where('evaluation.test_baseline', true));
        $this->post('/seller/quality/dialogs/'.$dialog.'/evaluate')->assertRedirect('/seller/evaluations/'.$evaluation->id);
        self::assertSame(1, DB::table('seller_evaluations')->where('conversation_id', $dialog)->count());
        $this->get('/seller/quality')->assertOk()->assertInertia(fn ($page) => $page->has('days', 0));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/seller/quality/dialogs/'.$dialog.'/evaluate')->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/quality/dialogs/'.$dialog.'/evaluate')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_empty_dialog_and_live_configuration_are_rejected(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialog = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        config(['runtime.ai_driver' => 'fake']);
        $this->post('/seller/quality/dialogs/'.$dialog.'/evaluate')->assertSessionHasErrors('evaluation');
        config(['runtime.ai_driver' => 'openrouter']);
        $this->post('/seller/quality/dialogs/'.$dialog.'/evaluate')->assertSessionHasErrors('evaluation');
        self::assertSame(0, DB::table('seller_evaluations')->where('conversation_id', $dialog)->count());
    }

    public function test_reviewed_fake_fixture_is_used_for_real_conversation(): void
    {
        [$owner, $org, $brand] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $dialog = (string) Str::ulid();
        DB::table('conversations')->insert(['id' => $dialog, 'organization_id' => $org->id, 'brand_id' => $brand->id, 'state' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('messages')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'conversation_id' => $dialog, 'role' => 'agent', 'text' => 'Здравствуйте', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $directory = storage_path('app/judge-test-'.Str::random(8));
        mkdir($directory, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $directory]);
        $body = app(Judge::class)->request([['agent' => 'Здравствуйте', 'meta' => []]], []);
        $data = ['naturalness' => 4, 'goal' => 4, 'facts' => 4, 'objections' => 4, 'brevity' => 4, 'comment' => 'Фикстура', 'issues' => []];
        file_put_contents($directory.'/'.CanonicalJson::hash($body).'.json', json_encode(['result' => ['content' => json_encode($data, JSON_UNESCAPED_UNICODE)]], JSON_THROW_ON_ERROR));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->post('/seller/quality/dialogs/'.$dialog.'/evaluate')->assertRedirect();
        $evaluation = DB::table('seller_evaluations')->where('conversation_id', $dialog)->first();
        self::assertSame('4.00', $evaluation->average);
        self::assertSame(ScenarioFallbackJudge::COMMENT, $evaluation->comment);
    }
}
