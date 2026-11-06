<?php

namespace Tests\Feature\Ai;

use App\Ai\Protected\SellerExtractorStages;
use App\ProtectedOperations\JobFirstTurnClaim;
use App\Seller\Conversations\Data\TurnClaim;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Conversations\Models\Message;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class JobExtractorStorageE306JTest extends TenancyTestCase
{
    public function test_job_claim_and_brand_isolation_without_http_session(): void
    {
        [$user, $org, $brand, $other, $member] = $this->cabinet();
        config(['runtime.ai_driver' => 'fake', 'features.E0-02.defaults.organization_id' => $org->id]);
        app(PromptSetsSeeder::class)->run();
        $owner = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        app(TenantContextRunner::class)->run($owner, fn () => app(YamlKnowledgeImporter::class)->importAndPublish($owner, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
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
        $extractor = app(SellerExtractorStages::class);
        [$request, $context, $digest, , $text] = $extractor->prepareJobInput($tenant, $claim);
        self::assertSame('Первое сообщение', $text);
        self::assertStringContainsString($text, json_encode($request->toArray(), JSON_UNESCAPED_UNICODE));
        self::assertSame($brand->id, $context->brandId);
        self::assertSame($digest, $extractor->prepareJobInput($tenant, $claim)[2]);
        $saved = $extractor->prepareJobStoredInput($tenant, $claim);
        self::assertSame($digest, $saved['digest']);
        self::assertStringContainsString($text, $saved['input']);
        self::assertSame($saved, $extractor->prepareJobStoredInput($tenant, $claim));
        $ciphertext = DB::table('protected_operation_receipts')->where('operation', 'seller.job_extractor_input')->value('result_ciphertext');
        self::assertIsString($ciphertext);
        self::assertStringNotContainsString($text, $ciphertext);
        foreach ([new TenantContext($org->id, $other->id, source: 'job'), new TenantContext($org->id, $brand->id, source: 'session')] as $invalid) {
            try {
                $extractor->prepareJobStoredInput($invalid, $claim);
                self::fail('Invalid job provenance accepted');
            } catch (LogicException $error) {
                self::assertSame('Protected extractor stage rejected.', $error->getMessage());
            }
        }
        $forged = new TurnClaim('claimed', $claim->turnId, $claim->conversationId, $claim->organizationId, $claim->brandId, $claim->baseVersion, str_repeat('0', 64));
        try {
            $extractor->prepareJobStoredInput($tenant, $forged);
            self::fail('Forged claim accepted');
        } catch (LogicException $error) {
            self::assertSame('Protected extractor stage rejected.', $error->getMessage());
        }
        config(['runtime.ai_driver' => 'live']);
        try {
            $extractor->prepareJobInput($tenant, $claim);
            self::fail('Live driver accepted');
        } catch (LogicException $error) {
            self::assertSame('Protected extractor stage rejected.', $error->getMessage());
        }
        config(['runtime.ai_driver' => 'fake']);
        self::assertDatabaseHas('e306g_job_turn_claims', ['turn_id' => $claim->turnId, 'brand_id' => $brand->id]);
        DB::transaction(fn () => $service->assertCurrent($tenant, $claim));
        $recovered = $service->claim($tenant, $conversation->id, $message->id, 'first');
        self::assertSame($claim->turnId, $recovered->turnId);
        self::assertSame($claim->token(), $recovered->token());
        DB::transaction(fn () => $service->assertCurrent($tenant, $recovered));
        self::assertSame($saved, $extractor->prepareJobStoredInput($tenant, $recovered));
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
