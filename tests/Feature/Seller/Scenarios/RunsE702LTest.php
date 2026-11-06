<?php

namespace Tests\Feature\Seller\Scenarios;

use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RunsE702LTest extends TenancyTestCase
{
    public function test_draft_run_and_access_validation(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $draft = app(TenantContextRunner::class)->run($tenant, fn () => app(DatabaseKnowledgeRepository::class)->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, [])));
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/scenarios', ['name' => 'Проверка', 'type' => 'newbie', 'behavior' => 'Вопрос', 'max_turns' => 2, 'expect' => []])->assertSessionHasNoErrors();
        $persona = DB::table('seller_personas')->where('brand_id', $brand->id)->first();
        $input = ['scenarios' => [$persona->id], 'knowledge' => 'draft', 'model' => 'fake'];
        $this->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page->where('draftAvailable', true));
        $this->post('/seller/quality/runs', $input)->assertSessionHasNoErrors();
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->sole();
        $this->assertSame($draft['id'], json_decode($run->metrics, true)['knowledge_release_id']);
        $this->post('/seller/quality/runs', [...$input, 'model' => 'unknown'])->assertSessionHasErrors('model');
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->post('/seller/quality/runs', $input)->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->post('/seller/quality/runs', $input)->assertSessionHasErrors('knowledge');
    }
}
