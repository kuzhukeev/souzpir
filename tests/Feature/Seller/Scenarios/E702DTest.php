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

final class E702DTest extends TenancyTestCase
{
    public function test_run_all_dispatches_only_current_brand_scenarios_in_set(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id,
                'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, []));
            $repo->publish($tenant, $draft['id'], 1);
        });
        foreach ([[$brand->id, 'first', true], [$brand->id, 'second', true], [$brand->id, 'excluded', false], [$other->id, 'foreign', true]] as [$brandId, $key, $inSet]) {
            DB::table('seller_personas')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brandId,
                'key' => $key, 'name' => $key, 'type' => 'newbie', 'payload' => json_encode(['in_set' => $inSet]),
                'max_turns' => 2, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/scenarios')->assertOk()->assertInertia(fn ($page) => $page->where('runAllAvailable', true));
        Queue::fake();
        $this->post('/seller/quality/scenarios/run-all')->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(SimulateFakeJob::class, 2);
        Queue::assertPushed(SimulateFakeJob::class, fn ($job) => $job->brandId === $brand->id && $job->personaKey === 'first');
        Queue::assertNotPushed(SimulateFakeJob::class, fn ($job) => $job->personaKey === 'foreign' || $job->personaKey === 'excluded');

        $this->post('/seller/quality/scenarios', ['name' => 'Criteria demo', 'type' => 'newbie', 'behavior' => 'Simple customer', 'max_turns' => 2,
            'expect' => ['partial_or_full_contact', 'no_prohibition_violation', 'no_deferred_promise', 'score_at_least_4']])->assertSessionHasNoErrors();
        $payload = json_decode(DB::table('seller_personas')->where('brand_id', $brand->id)->where('name', 'Criteria demo')->value('payload'), true);
        $this->assertSame(['partial_or_full_contact', 'no_prohibition_violation', 'no_deferred_promise', 'score_at_least_4'], $payload['expect']);
    }

    public function test_run_all_rejects_missing_knowledge_empty_set_and_viewer(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $this->get('/seller/quality/scenarios')->assertInertia(fn ($page) => $page->where('runAllAvailable', false));
        Queue::fake();
        $this->post('/seller/quality/scenarios/run-all')->assertSessionHasErrors('scenarios');
        Queue::assertNothingPushed();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->post('/seller/quality/scenarios/run-all')->assertForbidden();
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(TenantContextRunner::class)->run($tenant, function () use ($tenant): void {
            $repo = app(DatabaseKnowledgeRepository::class);
            $draft = $repo->createDraft($tenant, array_fill_keys(KnowledgeSnapshot::FILES, []));
            $repo->publish($tenant, $draft['id'], 1);
        });
        $this->post('/seller/quality/scenarios/run-all')->assertSessionHasErrors('scenarios');
        Queue::assertNothingPushed();
        $this->post('/seller/quality/scenarios', ['name' => 'Criteria demo', 'type' => 'newbie', 'behavior' => 'Simple customer', 'max_turns' => 2,
            'expect' => ['unknown_criterion']])->assertSessionHasErrors('expect.0');
    }
}
