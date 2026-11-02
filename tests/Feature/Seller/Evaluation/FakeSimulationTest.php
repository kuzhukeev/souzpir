<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Seller\Evaluation\FakeSimulation;
use App\Seller\Evaluation\Jobs\JudgeFakeJob;
use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Tenancy\TenantContext;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class FakeSimulationTest extends TenancyTestCase
{
    public function test_personas_fake_turns_evaluation_and_brand_access(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        [$viewer] = $this->member($org, 'viewer', [$brand->id]);
        $this->enableSeller($org->id, $brand->id);
        $this->enableSeller($org->id, $other->id);
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        self::assertSame(29, app(SellerPersonaSeeder::class)->seedForBrand($context));
        self::assertSame(29, DB::table('seller_personas')->where('brand_id', $brand->id)->count());
        $turns = [
            ['client' => 'Здравствуйте', 'agent' => 'Добрый день', 'meta' => ['goal' => 'intro']],
            ['client' => 'Нужен прайс', 'agent' => 'Уточните город', 'meta' => ['intent' => 'ask_price']],
            ['client' => 'Воронеж', 'agent' => 'Записал', 'meta' => ['guard' => 'ok']],
        ];
        SimulateFakeJob::dispatchSync($org->id, $brand->id, 'newbie_voronezh', $turns);
        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->first();
        self::assertSame(count($turns), $run->turn_count);
        self::assertSame(count($turns), count(json_decode($run->turns, true)));
        JudgeFakeJob::dispatchSync($org->id, $brand->id, $run->id, [
            'naturalness' => 4, 'goal' => 4, 'facts' => 5, 'objections' => 3, 'brevity' => 4,
            'comment' => 'Проверенный fake-результат', 'issues' => [],
        ]);
        $evaluation = DB::table('seller_evaluations')->where('sim_run_id', $run->id)->first();
        self::assertSame('4.00', $evaluation->average);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.simulation.completed', 'subject_id' => $run->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'seller.evaluation.created', 'subject_id' => $evaluation->id]);
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id])->getJson('/seller/evaluations/'.$evaluation->id)
            ->assertOk()->assertJsonPath('data.average', 4)->assertJsonPath('data.sim_run_id', $run->id);
        $this->flushSession()->actingAs($viewer)->withSession(['brand_id' => $brand->id])
            ->getJson('/seller/evaluations/'.$evaluation->id)->assertForbidden();
        $this->flushSession()->actingAs($owner)->withSession(['brand_id' => $other->id])
            ->getJson('/seller/evaluations/'.$evaluation->id)->assertNotFound();
    }

    public function test_invalid_persona_and_turns_do_not_create_run(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $context = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(SellerPersonaSeeder::class)->seedForBrand($context);
        try {
            app(FakeSimulation::class)->run($context, 'newbie_voronezh', []);
            self::fail('Empty fake run accepted.');
        } catch (InvalidArgumentException) {
            self::assertDatabaseCount('seller_sim_runs', 0);
        }
        $this->expectException(HttpException::class);
        app(FakeSimulation::class)->run($context, 'unknown_persona', [['client' => 'x', 'agent' => 'y']]);
    }

    private function enableSeller(string $organization, string $brand): void
    {
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $organization,
            'brand_id' => $brand, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}
