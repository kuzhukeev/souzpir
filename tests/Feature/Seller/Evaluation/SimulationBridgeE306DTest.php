<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Seller\Evaluation\SimulationRuntime;
use App\Tenancy\TenantContext;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class SimulationBridgeE306DTest extends TenancyTestCase
{
    public function test_enabled_job_passes_one_brand_through_the_runtime_interface(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        app(SellerPersonaSeeder::class)->seedForBrand(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        $runtime = new BridgeFixtureRuntime;
        app()->instance(SimulationRuntime::class, $runtime);
        config(['runtime.ai_driver' => 'fake', 'features.E3-06D.defaults.runtime_enabled' => true]);
        SimulateFakeJob::dispatchSync($org->id, $brand->id, 'newbie_voronezh', [[], []]);
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->sole();
        self::assertSame('completed', $run->status);
        self::assertSame(2, $run->turn_count);
        self::assertSame([$brand->id, $brand->id], $runtime->brands);
        self::assertSame(2, count(array_unique($runtime->keys)));
        self::assertCount(2, json_decode($run->turns, true, flags: JSON_THROW_ON_ERROR));
        try {
            SimulateFakeJob::dispatchSync($org->id, $other->id, 'newbie_voronezh', [[], []]);
            self::fail('Foreign brand persona accepted');
        } catch (HttpException) {
            self::assertSame(1, DB::table('seller_sim_runs')->count());
        }
        Http::assertNothingSent();
    }

    public function test_enabled_job_uses_protected_runtime_without_binding_and_rejects_live_ai(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(SellerPersonaSeeder::class)->seedForBrand(new TenantContext($org->id, $brand->id, $owner->id, $membership->id));
        config(['runtime.ai_driver' => 'fake', 'features.E3-06D.defaults.runtime_enabled' => true]);
        app()->forgetInstance(SimulationRuntime::class);
        SimulateFakeJob::dispatchSync($org->id, $brand->id, 'newbie_voronezh', [[], []]);
        // RefreshDatabase wraps this test in a transaction: protected AI must fail closed.
        // The unwrapped successful path is covered by ProtectedSimulationRuntimeTest.
        self::assertSame('failed', DB::table('seller_sim_runs')->sole()->status);

        config(['runtime.ai_driver' => 'openrouter']);
        $this->expectException(LogicException::class);
        SimulateFakeJob::dispatchSync($org->id, $brand->id, 'newbie_voronezh', [[]]);
    }
}

final class BridgeFixtureRuntime implements SimulationRuntime
{
    public array $brands = [];

    public array $keys = [];

    public function open(TenantContext $context, string $key): array
    {
        $this->brands[] = $context->brandId;
        $this->keys[] = $key;

        return ['conversation_id' => (string) Str::ulid(), 'agent' => 'Здравствуйте'];
    }

    public function turn(TenantContext $context, string $conversationId, string $client, string $key): array
    {
        $this->brands[] = $context->brandId;
        $this->keys[] = $key;

        return ['agent' => 'Принято'];
    }
}
