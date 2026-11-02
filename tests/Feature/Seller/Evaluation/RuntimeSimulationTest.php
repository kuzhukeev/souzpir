<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Seller\Evaluation\RuntimeSimulation;
use App\Seller\Evaluation\SimulationRuntime;
use App\Tenancy\TenantContext;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class RuntimeSimulationTest extends TenancyTestCase
{
    public function test_persona_client_runs_through_open_and_turn_and_saves_trace(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(SellerPersonaSeeder::class)->seedForBrand($context);
        $runtime = new TestSimulationRuntime;
        $id = app(RuntimeSimulation::class)->run($context, 'newbie_voronezh', 3, $runtime);
        $run = DB::table('seller_sim_runs')->where('id', $id)->first();
        self::assertSame('completed', $run->status);
        self::assertSame(3, $run->turn_count);
        self::assertSame(2, count($runtime->clients));
        self::assertStringContainsString('Воронеж', $runtime->clients[0]);
        self::assertCount(3, json_decode($run->turns, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(3, json_decode($run->metrics, true, 512, JSON_THROW_ON_ERROR)['agent_turns']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.simulation.completed', 'subject_id' => $id]);
        $this->expectException(HttpException::class);
        app(RuntimeSimulation::class)->run(new TenantContext($org->id, $other->id, $owner->id, $membership->id),
            'newbie_voronezh', 1, $runtime);
    }

    public function test_failed_runtime_turn_is_saved_and_invalid_count_is_rejected(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(SellerPersonaSeeder::class)->seedForBrand($context);
        $runtime = new TestSimulationRuntime;
        $runtime->fail = true;
        $id = app(RuntimeSimulation::class)->run($context, 'newbie_voronezh', 3, $runtime);
        $run = DB::table('seller_sim_runs')->where('id', $id)->first();
        self::assertSame('failed', $run->status);
        self::assertSame(1, $run->turn_count);
        self::assertSame(InvalidArgumentException::class, json_decode($run->metrics, true, 512, JSON_THROW_ON_ERROR)['error']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.simulation.failed', 'subject_id' => $id]);
        try {
            app(RuntimeSimulation::class)->run($context, 'newbie_voronezh', 0, $runtime);
            self::fail('Zero turns accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(1, DB::table('seller_sim_runs')->count());
        }
    }
}

final class TestSimulationRuntime implements SimulationRuntime
{
    public array $clients = [];

    public bool $fail = false;

    public function open(TenantContext $context, string $key): array
    {
        return ['conversation_id' => (string) Str::ulid(), 'agent' => 'Здравствуйте!'];
    }

    public function turn(TenantContext $context, string $conversationId, string $client, string $key): array
    {
        if ($this->fail) {
            throw new InvalidArgumentException('Fake runtime failed.');
        }
        $this->clients[] = $client;

        return ['agent' => 'Спасибо, понял.'];
    }
}
