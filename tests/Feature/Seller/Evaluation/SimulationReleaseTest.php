<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\RuntimeSimulation;
use App\Seller\Evaluation\SimulationRuntime;
use App\Seller\Knowledge\DatabaseKnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SimulationReleaseTest extends TenancyTestCase
{
    public function test_fake_and_runtime_record_release_and_keep_it_on_failure(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $otherContext = new TenantContext($org->id, $other->id, $owner->id, $membership->id);
        foreach ([$context, $otherContext] as $tenant) {
            app(SellerPersonaSeeder::class)->seedForBrand($tenant);
        }
        $release = app(TenantContextRunner::class)->run($context, function () use ($context): string {
            $repository = app(DatabaseKnowledgeRepository::class);
            $draft = $repository->createDraft($context, array_fill_keys(KnowledgeSnapshot::FILES, []));

            return $repository->publish($context, $draft['id'], 1)->releaseId();
        });
        $turns = [['client' => 'Вопрос', 'agent' => 'Ответ']];
        $fake = app(FakeSimulation::class)->run($context, 'newbie_voronezh', $turns);
        $runtime = new class implements SimulationRuntime
        {
            public bool $fail = false;

            public function open(TenantContext $context, string $key): array
            {
                return ['conversation_id' => (string) Str::ulid(), 'agent' => 'Здравствуйте'];
            }

            public function turn(TenantContext $context, string $conversationId, string $client, string $key): array
            {
                if ($this->fail) {
                    throw new InvalidArgumentException;
                }

                return ['agent' => 'Ответ'];
            }
        };
        $success = app(RuntimeSimulation::class)->run($context, 'newbie_voronezh', 1, $runtime);
        $runtime->fail = true;
        $failure = app(RuntimeSimulation::class)->run($context, 'newbie_voronezh', 2, $runtime);
        $without = app(FakeSimulation::class)->run($otherContext, 'newbie_voronezh', $turns);
        $runtime->fail = false;
        $withoutRuntime = app(RuntimeSimulation::class)->run($otherContext, 'newbie_voronezh', 1, $runtime);
        foreach ([$fake, $success, $failure] as $id) {
            self::assertSame($release, json_decode(DB::table('seller_sim_runs')->where('id', $id)->value('metrics'), true)['knowledge_release_id']);
        }
        self::assertSame('failed', DB::table('seller_sim_runs')->where('id', $failure)->value('status'));
        self::assertNull(json_decode(DB::table('seller_sim_runs')->where('id', $without)->value('metrics'), true)['knowledge_release_id']);
        self::assertNull(json_decode(DB::table('seller_sim_runs')->where('id', $withoutRuntime)->value('metrics'), true)['knowledge_release_id']);
        foreach ([[$brand, $fake], [$other, $without]] as [$item, $run]) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('seller_evaluations')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'sim_run_id' => $run, 'scores' => '{}', 'issues' => '[]', 'average' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->get('/seller/quality/compare')->assertOk()->assertInertia(fn ($page) => $page->has('runs', 1)->where('runs.0.knowledge_version', 1));
        $this->actingAs($owner)->withSession(['brand_id' => $other->id])->get('/seller/quality/compare')->assertOk()->assertInertia(fn ($page) => $page->has('runs', 1)->where('runs.0.knowledge_version', null));
        [$outsider] = $this->member($org, 'member', []);
        $this->actingAs($outsider)->withSession(['brand_id' => $brand->id])->get('/seller/quality/compare')->assertForbidden();
        try {
            app(FakeSimulation::class)->run($context, 'newbie_voronezh', []);
            self::fail('Пустой прогон принят');
        } catch (InvalidArgumentException) {
            self::assertSame(5, DB::table('seller_sim_runs')->count());
        }
        $this->expectException(HttpException::class);
        app(FakeSimulation::class)->run($otherContext, 'unknown', $turns);
    }
}
