<?php

namespace Tests\Feature\Seller\Scenarios;

use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RunsE702CTest extends TenancyTestCase
{
    public function test_start_validation_rights_and_brand_isolation(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page->component('Seller/Scenarios/Runs')->has('runs', 0));
        $this->post('/seller/quality/runs', ['scenarios' => [], 'knowledge' => 'published', 'model' => 'fake'])->assertSessionHasErrors('scenarios');
        $this->post('/seller/quality/runs', ['scenarios' => [(string) Str::ulid()], 'knowledge' => 'draft', 'model' => 'fake'])->assertSessionHasErrors('knowledge');
        Queue::fake();
        $this->post('/seller/quality/runs', ['scenarios' => [(string) Str::ulid()], 'knowledge' => 'published', 'model' => 'fake'])->assertSessionHasErrors('knowledge');
        Queue::assertNothingPushed();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])->get('/seller/quality/runs')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/quality/runs')->assertOk()->assertInertia(fn ($page) => $page->has('scenarios', 0)->has('runs', 0));
    }

    public function test_selected_scenario_dispatches_fake_runtime_with_published_knowledge(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, []));
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->post('/seller/quality/scenarios', ['name' => 'Тестовый сценарий', 'type' => 'newbie', 'behavior' => 'Вопрос', 'max_turns' => 2, 'expect' => []])->assertSessionHasNoErrors();
        $persona = DB::table('seller_personas')->where('brand_id', $brand->id)->first();
        Queue::fake();
        $this->post('/seller/quality/runs', ['scenarios' => [$persona->id], 'knowledge' => 'published', 'model' => 'fake'])->assertSessionHasNoErrors()->assertRedirect();
        Queue::assertPushed(SimulateFakeJob::class, fn ($job) => $job->brandId === $brand->id && $job->personaKey === $persona->key && count($job->turns) === 2);
    }
}
