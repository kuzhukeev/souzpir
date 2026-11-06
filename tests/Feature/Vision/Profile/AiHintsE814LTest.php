<?php

namespace Tests\Feature\Vision\Profile;

use App\Models\User;
use App\Shell\Auth\AuthSessionRegistry;
use App\Tenancy\TenantContext;
use App\Vision\Competitors\SnapshotView;
use App\Vision\Profile\AiCompetitorHints;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Tenancy\TenancyTestCase;

final class AiHintsE814LTest extends TenancyTestCase
{
    private function snapshot(): array
    {
        return ['providers' => ['fake' => 'fake-model'], 'metrics' => ['entities' => [['name' => 'Сосед', 'is_brand' => false], ['name' => 'Наш бренд', 'is_brand' => true]], 'slots' => [
            ['provider' => 'fake', 'valid' => true, 'mentions_complete' => true, 'question_n' => 1, 'mentions' => [['name' => 'Сосед'], ['name' => 'Наш бренд']]],
            ['provider' => 'fake', 'valid' => false, 'mentions_complete' => true, 'question_n' => 2, 'mentions' => [['name' => 'Чужой']]],
            ['provider' => 'real', 'valid' => true, 'mentions_complete' => true, 'question_n' => 3, 'mentions' => [['name' => 'Сосед']]],
        ]]];
    }

    public function test_only_complete_fake_answer_with_confirmed_entity_is_offered(): void
    {
        $hints = app(AiCompetitorHints::class)->extract($this->snapshot());
        self::assertSame(['Сосед'], array_column($hints, 'value'));
        self::assertSame('Подтверждённый замер, вопрос 1', $hints[0]['source']);
        $snapshot = $this->snapshot();
        unset($snapshot['providers']['fake']);
        self::assertSame([], app(AiCompetitorHints::class)->extract($snapshot));
    }

    public function test_hints_respect_brand_and_read_permissions(): void
    {
        URL::useOrigin('http://127.0.0.1');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brands')->where('id', $item->id)->update(['website' => 'https://example.test']);
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'vision', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        app()->instance(SnapshotView::class, new class($brand->id, $this->snapshot()) extends SnapshotView
        {
            public function __construct(private string $id, private array $snapshot) {}

            public function current(TenantContext $tenant): ?array
            {
                return $tenant->brandId === $this->id ? $this->snapshot : null;
            }
        });
        $this->signIn($owner, $brand->id);
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn ($page) => $page->where('hints.items.0.value', 'Сосед')->where('hints.items.0.kind', 'ai_competitor'));
        DB::table('brands')->where('id', $brand->id)->update(['website' => null]);
        $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn ($page) => $page->where('hints.items.0.value', 'Сосед'));
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->post('/vision/quiz/start')->assertRedirect();
        $this->privateGet('/vision/quiz/4')->assertOk()->assertInertia(fn ($page) => $page->where('hints.items', []));
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/vision/quiz/start')->assertForbidden();
    }

    private function privateGet(string $path): TestResponse
    {
        $this->get($path)->assertRedirect('/vision/private-access');
        $page = $this->get('/vision/private-access')->assertOk();
        self::assertSame(1, preg_match("/const h='([a-f0-9]{64})'/", $page->getContent(), $matches));
        $this->post('/vision/private-access', ['proof' => hash('sha256', hex2bin($matches[1]))])->assertRedirect($path);

        return $this->get($path);
    }

    private function signIn(User $user, string $brandId): void
    {
        $user->refresh();
        $this->actingAs($user);
        $this->startSession();
        session()->invalidate();
        session()->put(['auth.version' => $user->auth_version, 'brand_id' => $brandId]);
        $request = Request::create('/', 'GET');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);
        app(AuthSessionRegistry::class)->issue($request, $user, $user->auth_version);
        session()->save();
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
    }
}
