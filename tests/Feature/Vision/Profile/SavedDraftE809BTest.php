<?php

namespace Tests\Feature\Vision\Profile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SavedDraftE809BTest extends TenancyTestCase
{
    public function test_saved_time_only_belongs_to_the_current_brand_draft(): void
    {
        config(['app.asset_url' => 'http://127.0.0.1']);
        URL::useOrigin('http://127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/vision/quiz/start')->assertRedirect('/vision/quiz/1');
        $quiz = DB::table('visibility_quizzes')->where('brand_id', $brand->id)->sole();
        $this->openPrivate('/vision/quiz/1')->assertOk()->assertInertia(fn ($page) => $page->where('savedAt', null));
        $this->patch('/vision/quiz/step/1', ['quiz_id' => $quiz->id, 'revision' => 1, 'values' => ['brand_name' => 'Бренд', 'site' => 'https://example.com']])->assertRedirect()->assertSessionHasNoErrors();
        $this->openPrivate('/vision/quiz/2')->assertOk()->assertInertia(fn ($page) => $page->has('savedAt')->where('quiz.revision', 2));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/vision/quiz/1')->assertRedirect();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/quiz/start')->assertForbidden();
        Http::assertNothingSent();
    }

    private function openPrivate(string $path): TestResponse
    {
        $this->get($path)->assertRedirect('/vision/private-access');
        $page = $this->get('/vision/private-access')->assertOk();
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $page->getContent(), $matches));
        $this->post('/vision/private-access', ['proof' => hash('sha256', hex2bin($matches[1]))])->assertRedirect($path);

        return $this->get($path);
    }
}
