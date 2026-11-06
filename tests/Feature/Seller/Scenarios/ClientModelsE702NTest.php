<?php

namespace Tests\Feature\Seller\Scenarios;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClientModelsE702NTest extends TenancyTestCase
{
    public function test_draft_run_and_access_validation(): void
    {
        config()->set('features.E7-02N.defaults.client_models', ['facts' => ['label' => 'Факты', 'strategy' => 'facts'], 'behavior' => ['label' => 'Поведение', 'strategy' => 'behavior']]);
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $draft = app(TenantContextRunner::class)->run($tenant, fn () => app(DatabaseKnowledgeRepository::class)->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, [])));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/scenarios', ['name' => 'Проверка', 'type' => 'newbie', 'behavior' => 'Вопрос', 'max_turns' => 2, 'expect' => []])->assertSessionHasNoErrors();
        $persona = DB::table('seller_personas')->where('brand_id', $brand->id)->first();
        DB::table('seller_personas')->where('id', $persona->id)->update(['payload' => json_encode(['behavior' => 'Нужна консультация', 'facts' => ['city' => 'Москва']], JSON_UNESCAPED_UNICODE)]);
        $input = ['scenarios' => [$persona->id], 'knowledge' => 'draft', 'model' => 'facts'];
        $this->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page->where('draftAvailable', true));
        $this->post('/seller/quality/runs', $input)->assertSessionHasNoErrors();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->sole();
        $this->assertSame($draft['id'], json_decode($run->metrics, true)['knowledge_release_id']);
        $this->assertStringContainsString('Москва', json_decode($run->turns, true)[0]['client']);
        $this->post('/seller/quality/runs', [...$input, 'model' => 'behavior'])->assertSessionHasNoErrors();
        $runs = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->get();
        $this->assertTrue($runs->contains(fn ($row) => str_contains(json_decode($row->turns, true)[0]['client'], 'Нужна консультация')));
        $this->assertSame(0, DB::table('seller_evaluations')->whereIn('sim_run_id', $runs->pluck('id'))->count());
        DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'sim_run_id' => $run->id, 'scores' => '{"goal":5}', 'issues' => '[]', 'average' => 5, 'comment' => 'unconfirmed', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/seller/quality/runs')->assertInertia(fn ($page) => $page->where('runs.0.average', null)->where('runs.1.average', null)->where('groups.0.average', null)->where('groups.1.average', null));
        $this->post('/seller/quality/runs', [...$input, 'model' => 'unknown'])->assertSessionHasErrors('model');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/quality/runs', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/quality/runs', $input)->assertSessionHasErrors('knowledge');
    }
}
