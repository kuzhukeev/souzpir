<?php

namespace Tests\Feature\Seller\Channels\Api;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Ai\Gateway;
use App\Ai\Protected\ApiFollowupInput;
use App\Ai\Protected\ApiOpeningInput;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Contracts\ConversationRepository;
use App\Seller\Conversations\Data\TurnCommand;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use App\Tenancy\TokenApi\BrandAccessToken;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApiOpeningE503FTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_fake_opening_is_guarded_saved_and_replayed_without_client_message(): void
    {
        Http::preventStrayRequests();
        config(['runtime.ai_driver' => 'fake', 'cache.default' => 'array']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_ADDR' => '127.0.0.1']);
        app(PromptSetsSeeder::class)->run();
        $user = User::create(['name' => 'Test', 'email' => 'open@example.test', 'password' => 'synthetic-password']);
        $org = Organization::create(['name' => 'Test', 'owner_user_id' => $user->id]);
        [$member, $brand] = app(TenantContextRunner::class)->run(new TenantContext($org->id, actorId: $user->id), fn () => [
            Membership::create(['user_id' => $user->id, 'role' => 'owner']), Brand::create(['name' => 'Test', 'timezone' => 'Europe/Moscow']),
        ]);
        DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $brand->id,
            'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $sessionTenant = new TenantContext($org->id, $brand->id, $user->id, $member->id);
        app(TenantContextRunner::class)->run($sessionTenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($sessionTenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $secret = Str::random(64);
        $tokenId = DB::table('personal_access_tokens')->insertGetId(['tokenable_type' => Brand::class, 'tokenable_id' => $brand->id,
            'organization_id' => $org->id, 'brand_id' => $brand->id, 'name' => 'test', 'token' => hash('sha256', $secret),
            'token_last_four' => substr($secret, -4), 'abilities' => json_encode(['leads', 'turn']), 'created_at' => now(), 'updated_at' => now()]);
        $bearer = $tokenId.'|'.$secret;
        $body = ['fields' => ['name' => 'Тест']];
        $initial = $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'fixture'])
            ->postJson('/api/v1/leads', $body)->assertCreated()->json();
        $lead = DB::table('seller_api_lead_requests')->where('conversation_id', $initial['conversation_id'])->first();
        DB::table('seller_api_lead_requests')->where('id', $lead->id)->update(['status' => 'opening']);
        $request = Request::create('/api/v1/leads', 'POST');
        $request->setUserResolver(fn () => $brand);
        $request->attributes->set('tenant.token', BrandAccessToken::query()->findOrFail($tokenId));
        $tenant = new TenantContext($org->id, $brand->id, source: 'token');
        [$aiRequest] = app(TenantContextRunner::class)->run($tenant, fn () => DB::transaction(fn () => app(ApiOpeningInput::class)->buildLocked($request, $tenant, $lead->id)));
        $directory = base_path('.local/tmp/api-opening-fixtures');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        config(['features.E0-05.defaults.fixture_directory' => $directory]);
        $fixture = $directory.'/'.CanonicalJson::hash(app(ContactMasker::class)->request($aiRequest->toArray())).'.json';
        file_put_contents($fixture, json_encode(['result' => ['content' => 'Здравствуйте! Из какого вы города?', 'toolCalls' => [],
            'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $aiRequest->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
        $headers = ['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'real-open'];
        $open = [...$body, 'action' => 'open'];
        $first = $this->withHeaders($headers)->postJson('/api/v1/leads', $open)->assertCreated()->assertJsonPath('status', 'opened')->json();
        $this->withHeaders($headers)->postJson('/api/v1/leads', $open)->assertOk()->assertExactJson($first);
        $this->withHeaders($headers)->postJson('/api/v1/leads', [...$open, 'fields' => ['name' => 'Другой']])->assertConflict();
        self::assertSame(1, DB::table('messages')->where('conversation_id', $first['conversation_id'])->where('role', 'agent')->count());
        self::assertSame(0, DB::table('messages')->where('conversation_id', $first['conversation_id'])->where('role', 'client')->count());
        self::assertSame(1, DB::table('conversations')->where('id', $first['conversation_id'])->value('version'));
        $claim = app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $request, $first, $org, $brand) {
            return DB::transaction(function () use ($tenant, $request, $first, $org, $brand) {
                $message = (string) Str::ulid();
                DB::table('messages')->insert(['id' => $message, 'organization_id' => $org->id, 'brand_id' => $brand->id,
                    'conversation_id' => $first['conversation_id'], 'role' => 'client', 'text' => 'Из Твери', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()]);
                $claim = app(ConversationRepository::class)->claimTurn($tenant, new TurnCommand($first['conversation_id'], $message, 'Из Твери', null, 'api:extractor-test', 1));
                [$input, , , , $text, , $history] = app(ApiFollowupInput::class)->extractorLocked($request, $tenant, $claim);
                self::assertSame('Из Твери', $text);
                self::assertSame('agent', $history[0]['role']);
                self::assertStringContainsString('Из Твери', $input->messages[array_key_last($input->messages)]['content']);

                return $claim;
            });
        });
        [$extractor] = app(TenantContextRunner::class)->run($tenant, fn () => DB::transaction(fn () => app(ApiFollowupInput::class)->extractorLocked($request, $tenant, $claim)));
        $extractorFixture = $directory.'/'.CanonicalJson::hash(app(ContactMasker::class)->request($extractor->toArray())).'.json';
        file_put_contents($extractorFixture, json_encode(['result' => ['content' => '{"client_intent":"answer_only"}', 'toolCalls' => [],
            'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $extractor->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
        $extraction = app(TenantContextRunner::class)->run($tenant, fn () => app(Gateway::class)->completeApiFollowupExtractor($request, $tenant, $claim));
        self::assertTrue($extraction->ok());
        self::assertSame('answer_only', $extraction->json()['client_intent']);
        DB::table('brands')->where('id', $brand->id)->update(['settings' => json_encode(['seller_api_action' => 'open'])]);
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'default-open'])
            ->postJson('/api/v1/leads', $body)->assertCreated()->assertJsonPath('status', 'opened');
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'override-card'])
            ->postJson('/api/v1/leads', [...$body, 'action' => 'card_only'])->assertCreated()->assertJsonPath('status', 'created');
        file_put_contents($fixture, json_encode(['result' => ['content' => 'Гарантируем скидку 99 процентов!', 'toolCalls' => [],
            'usage' => ['promptTokens' => 1, 'completionTokens' => 1], 'model' => $aiRequest->model, 'latencyMs' => 1, 'costMicros' => 0]], JSON_THROW_ON_ERROR));
        $this->withHeaders(['Authorization' => 'Bearer '.$bearer, 'Idempotency-Key' => 'unsafe-open'])
            ->postJson('/api/v1/leads', $open)->assertStatus(503);
        $unsafe = DB::table('seller_api_lead_requests')->where('idempotency_key', 'unsafe-open')->first();
        self::assertSame('opening', $unsafe->status);
        self::assertSame(0, DB::table('messages')->where('conversation_id', $unsafe->conversation_id)->count());
        Http::assertNothingSent();
    }
}
