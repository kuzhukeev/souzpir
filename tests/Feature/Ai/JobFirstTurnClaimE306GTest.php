<?php

namespace Tests\Feature\Ai;

use App\ProtectedOperations\JobFirstTurnClaim;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class JobFirstTurnClaimE306GTest extends TenancyTestCase
{
    public function test_job_claim_and_brand_isolation_without_http_session(): void
    {
        [, $org, $brand, $other] = $this->cabinet();
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        [$conversation, $message] = $this->within($org, $brand, function (): array {
            $conversation = Conversation::create(['state' => [], 'mode' => 'bot']);

            return [$conversation, Message::create(['conversation_id' => $conversation->id, 'role' => 'client', 'text' => 'Первое сообщение', 'meta' => []])];
        });
        $service = app(JobFirstTurnClaim::class);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        foreach ([new TenantContext($org->id, $other->id, source: 'job'), new TenantContext($org->id, $brand->id, source: 'session')] as $invalid) {
            try {
                $service->claim($invalid, $conversation->id, $message->id, 'first');
                self::fail('Invalid tenant accepted');
            } catch (LogicException) {
                self::assertSame(0, DB::table('seller_turns')->count());
            }
        }
        $claim = $service->claim($tenant, $conversation->id, $message->id, 'first');
        self::assertSame('claimed', $claim->status);
        self::assertDatabaseHas('e306g_job_turn_claims', ['turn_id' => $claim->turnId, 'brand_id' => $brand->id]);
        DB::transaction(fn () => $service->assertCurrent($tenant, $claim));
        self::assertSame(1, DB::table('seller_turns')->count());
    }

    public function test_invalid_id_and_live_driver_are_rejected(): void
    {
        [, $org, $brand] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        try {
            app(JobFirstTurnClaim::class)->claim($tenant, 'bad', 'bad', '');
            self::fail('Invalid input accepted');
        } catch (LogicException) {
            self::assertSame(0, DB::table('seller_turns')->count());
        }
        config(['runtime.ai_driver' => 'live']);
        $this->expectException(LogicException::class);
        app(JobFirstTurnClaim::class)->claim($tenant, 'bad', 'bad', '');
    }
}
