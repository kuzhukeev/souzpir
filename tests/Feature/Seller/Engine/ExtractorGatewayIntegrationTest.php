<?php

namespace Tests\Feature\Seller\Engine;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Models\Brand;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Engine\ExtractionPending;
use App\Seller\Engine\ExtractionUncertain;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class FixtureBackedGateway implements AiGateway
{
    public array $files = [];

    public array $bodies = [];

    public function __construct(private AiGateway $delegate, private string $directory) {}

    public function complete(AiRequest $request, AiContext $context): AiResult
    {
        $body = app(ContactMasker::class)->request($request->toArray());
        $this->bodies[] = $body;
        $file = $this->directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($body).'.json';
        $this->files[] = $file;
        $result = ['content' => '{"client_intent":"answer_only","slot_updates":{"email":"[EMAIL]","messenger":"[PHONE]"}}', 'toolCalls' => [], 'usage' => ['promptTokens' => 5, 'completionTokens' => 3], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0];
        file_put_contents($file, json_encode(['result' => $result], JSON_THROW_ON_ERROR));

        return $this->delegate->complete($request, $context);
    }
}

final class ExtractorGatewayIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_extractor_reaches_real_gateway_fake_driver_and_persists_usage_without_network(): void
    {
        Http::fake();
        $owner = User::create(['name' => 'Owner', 'email' => 'engine-gateway@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$membership, $brandModel] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), function () use ($owner): array {
            return [Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Brand::create(['name' => 'Test', 'theme' => 'neutral'])];
        });
        $org = $organization->id;
        $brand = $brandModel->id;
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org, $brand, $owner->id, $membership->id);
        [$snapshot, $conversation] = app(TenantContextRunner::class)->run($tenant, fn () => [
            app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')),
            Conversation::create(['state' => []]),
        ]);
        $directory = storage_path('app/engine-fixture-'.Str::random(20));
        mkdir($directory, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $directory]);
        $gateway = new FixtureBackedGateway(app(AiGateway::class), $directory);
        try {
            app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $snapshot, $gateway, $org, $brand, $conversation): void {
                $prompt = app(ExtractorPromptLoader::class)->load($tenant);
                $engine = new Extractor($gateway, $snapshot, $prompt, 'google/gemini-2.5-flash', 0.0, 'Антон');
                $context = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation->id, 'engine-integration', ['conversation_content', 'client_profile', 'knowledge_internal']);
                $first = $engine->extract(new LeadState, [], 'Hello', $context);
                $second = $engine->extract(new LeadState, [], 'Hello', $context);
                $this->assertSame('answer_only', $first['client_intent']);
                $this->assertSame($first, $second);
                $this->assertSame(1, DB::table('ai_calls')->where('purpose', 'extractor')->count());
                $this->assertSame(1, DB::table('object_costs')->count());
                $emailContext = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation->id, 'engine-email', ['conversation_content', 'client_profile', 'knowledge_internal']);
                $emailResult = $engine->extract(new LeadState, [], 'Use a.person+tag@example.test or +7 (999) 111-22-33', $emailContext);
                $this->assertSame('a.person+tag@example.test', $emailResult['slot_updates']['email']);
                $this->assertNull($emailResult['slot_updates']['messenger']);
                $body = json_encode($gateway->bodies[array_key_last($gateway->bodies)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('a.person+tag@example.test', $body);
                $this->assertStringContainsString('[EMAIL]', $body);
                $this->assertStringNotContainsString('+7 (999) 111-22-33', $body);
                $this->assertStringContainsString('[ТЕЛЕФОН]', $body);
                $repeatContext = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation->id, 'engine-email-repeat', ['conversation_content', 'client_profile', 'knowledge_internal']);
                $repeat = $engine->extract(new LeadState, [], 'a@example.test / A@example.test', $repeatContext);
                $this->assertSame('A@example.test', $repeat['slot_updates']['email']);
                $repeatBody = json_encode($gateway->bodies[array_key_last($gateway->bodies)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('a@example.test', mb_strtolower($repeatBody));
                $ambiguousContext = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation->id, 'engine-email-ambiguous', ['conversation_content', 'client_profile', 'knowledge_internal']);
                $ambiguous = $engine->extract(new LeadState, [], 'a@example.test or b@example.test', $ambiguousContext);
                $this->assertNull($ambiguous['slot_updates']['email']);
                $ambiguousBody = json_encode($gateway->bodies[array_key_last($gateway->bodies)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('a@example.test', $ambiguousBody);
                $this->assertStringNotContainsString('b@example.test', $ambiguousBody);
                $bad = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation->id, 'missing-classes', ['knowledge_public']);
                try {
                    $engine->extract(new LeadState, [], 'Hello', $bad);
                    $this->fail('Missing data classes accepted');
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('required data class', $e->getMessage());
                }
                $this->assertSame(4, DB::table('ai_calls')->count());
                DB::table('ai_calls')->where('idempotency_key_hash', hash_hmac('sha256', 'engine-integration', config('app.key')))->update(['status' => 'reserved']);
                try {
                    $engine->extract(new LeadState, [], 'Hello', $context);
                    $this->fail('In-progress fallback returned');
                } catch (ExtractionPending) {
                    $this->assertTrue(true);
                }
                DB::table('ai_calls')->where('idempotency_key_hash', hash_hmac('sha256', 'engine-integration', config('app.key')))->update(['status' => 'uncertain', 'error_code' => 'provider_uncertain']);
                try {
                    $engine->extract(new LeadState, [], 'Hello', $context);
                    $this->fail('Uncertain fallback returned');
                } catch (ExtractionUncertain $e) {
                    $this->assertSame('provider_uncertain', $e->reason);
                }
                $this->assertSame(4, DB::table('ai_calls')->count());
                DB::table('brands')->where('id', $brand)->update(['settings' => json_encode(['ai_data_policy' => ['knowledge_internal' => ['any'], 'conversation_content' => ['any']]], JSON_THROW_ON_ERROR)]);
                config(['runtime.ai_driver' => 'openrouter', 'features.E0-05.defaults.live_enabled' => true, 'features.E0-05.defaults.openrouter_key' => 'test-only']);
                $live = new Extractor(app(AiGateway::class), $snapshot, $prompt, 'google/gemini-2.5-flash', 0.0, 'Anton');
                $blocked = new AiContext($org, $brand, 'extractor', 'seller', 'conversation', $conversation->id, 'no-client-profile-consent', ['conversation_content', 'client_profile', 'knowledge_internal']);
                try {
                    $live->extract(new LeadState, [], 'Hello', $blocked);
                    $this->fail('Forbidden transfer accepted');
                } catch (\LogicException $e) {
                    $this->assertStringContainsString('data policy', $e->getMessage());
                }
                $this->assertSame(4, DB::table('ai_calls')->count());
            });
            Http::assertNothingSent();
        } finally {
            foreach (array_unique($gateway->files) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_concurrent_real_gateway_call_returns_pending_without_second_driver_or_fallback(): void
    {
        Http::fake();
        $owner = User::create(['name' => 'Owner', 'email' => 'engine-race@example.test', 'password' => 'test-only-password']);
        $organization = Organization::create(['name' => 'Test', 'owner_user_id' => $owner->id]);
        [$membership, $brandModel] = app(TenantContextRunner::class)->run(new TenantContext($organization->id, source: 'job'), fn () => [Membership::create(['user_id' => $owner->id, 'role' => 'owner']), Brand::create(['name' => 'Test', 'theme' => 'neutral'])]);
        $tenant = new TenantContext($organization->id, $brandModel->id, $owner->id, $membership->id);
        app(PromptSetsSeeder::class)->run();
        [$snapshot, $conversation] = app(TenantContextRunner::class)->run($tenant, fn () => [app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')), Conversation::create(['state' => []])]);
        config(['runtime.ai_driver' => 'fake']);
        $directory = storage_path('app/engine-race-'.Str::random(20));
        mkdir($directory, 0700, true);
        $marker = $directory.DIRECTORY_SEPARATOR.'started';
        $releaseFile = $directory.DIRECTORY_SEPARATOR.'release';
        $process = new Process([PHP_BINARY, base_path('tests/Feature/Seller/Engine/Fixtures/active-call-worker.php'), $marker, $releaseFile, $organization->id, $brandModel->id, $conversation->id, $snapshot->releaseId(), config('database.connections.pgsql.database')], base_path(), ['APP_ENV' => 'testing']);
        try {
            $process->start();
            $deadline = microtime(true) + 15;
            while (! file_exists($marker) && $process->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $this->assertFileExists($marker, $process->getErrorOutput());
            app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $snapshot, $organization, $brandModel, $conversation): void {
                $prompt = app(ExtractorPromptLoader::class)->load($tenant);
                $engine = new Extractor(app(AiGateway::class), $snapshot, $prompt, 'google/gemini-2.5-flash', 0.0, 'Anton');
                $context = new AiContext($organization->id, $brandModel->id, 'extractor', 'seller', 'conversation', $conversation->id, 'race-key', ['conversation_content', 'client_profile', 'knowledge_internal']);
                try {
                    $engine->extract(new LeadState, [], 'Race message', $context);
                    $this->fail('In-progress fallback returned');
                } catch (ExtractionPending) {
                    $this->assertTrue(true);
                }
                $this->assertSame(1, DB::table('ai_calls')->count());
                $this->assertDatabaseHas('ai_calls', ['status' => 'reserved']);
                $this->assertDatabaseCount('seller_turns', 0);
            });
            file_put_contents($releaseFile, 'go');
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('answer_only', trim($process->getOutput()));
            $this->assertSame(1, DB::table('ai_calls')->count());
            $this->assertDatabaseHas('ai_calls', ['status' => 'completed']);
            Http::assertNothingSent();
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
            if (is_file($marker)) {
                unlink($marker);
            }
            if (is_file($releaseFile)) {
                unlink($releaseFile);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
