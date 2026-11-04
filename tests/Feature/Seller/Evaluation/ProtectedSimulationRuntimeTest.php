<?php

namespace Tests\Feature\Seller\Evaluation;

use App\Contracts\ProtectedOperations\IdempotencyConflict;
use App\Contracts\ProtectedOperations\IdempotencyFailure;
use App\ProtectedOperations\SellerSimulation\ProtectedSimulationRuntime;
use App\Seller\Evaluation\Jobs\SimulateFakeJob;
use App\Tenancy\TenantContext;
use Database\Seeders\Seller\Evaluation\SellerPersonaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProtectedSimulationRuntimeTest extends TenancyTestCase
{
    public function test_job_runs_persona_through_protected_fake_conversation(): void
    {
        Http::preventStrayRequests();
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        self::assertSame(29, app(SellerPersonaSeeder::class)->seedForBrand(new TenantContext($org->id, $brand->id, $owner->id, $membership->id)));
        SimulateFakeJob::dispatchSync($org->id, $brand->id, 'newbie_voronezh', [[], [], []]);

        $run = DB::table('seller_sim_runs')->where('brand_id', $brand->id)->sole();
        self::assertSame('completed', $run->status);
        self::assertSame(3, $run->turn_count);
        self::assertSame(1, DB::table('conversations')->where('brand_id', $brand->id)->count());
        self::assertSame(5, DB::table('messages')->where('brand_id', $brand->id)->count());
        self::assertSame(2, DB::table('seller_turns')->where('brand_id', $brand->id)->count());
        Http::assertNothingSent();
    }

    public function test_job_entrypoint_checks_brand_key_and_input(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        $runtime = app(ProtectedSimulationRuntime::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $opened = $runtime->open($tenant, 'open-1');
        self::assertSame($opened, $runtime->open($tenant, 'open-1'));
        $reply = $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'turn-1');
        self::assertSame($reply, $runtime->turn($tenant, $opened['conversation_id'], 'Воронеж', 'turn-1'));
        self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());

        try {
            $runtime->turn($tenant, $opened['conversation_id'], 'Другой текст', 'turn-1');
            self::fail('Changed input was accepted for the same key.');
        } catch (IdempotencyConflict) {
            self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
        }
        try {
            $runtime->turn(new TenantContext($org->id, $other->id, source: 'job'), $opened['conversation_id'], 'Воронеж', 'other-turn');
            self::fail('Foreign brand was accepted.');
        } catch (IdempotencyFailure) {
            self::assertSame(3, DB::table('messages')->where('conversation_id', $opened['conversation_id'])->count());
        }
        try {
            $runtime->open(new TenantContext($org->id, $brand->id, source: 'session'), 'session-open');
            self::fail('Session context was accepted.');
        } catch (LogicException) {
            self::assertSame(1, DB::table('conversations')->where('brand_id', $brand->id)->count());
        }
        $this->expectException(InvalidArgumentException::class);
        $runtime->turn($tenant, $opened['conversation_id'], '', 'bad-turn');
    }
}
