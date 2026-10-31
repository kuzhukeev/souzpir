<?php

namespace Tests\Feature\Seller\Engine;

use App\Ai\CanonicalJson;
use App\Ai\ContactMasker;
use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class FixtureBackedGateway implements AiGateway
{
    public ?string $file = null;

    public function __construct(private AiGateway $delegate, private string $directory) {}

    public function complete(AiRequest $request, AiContext $context): AiResult
    {
        $body = app(ContactMasker::class)->request($request->toArray());
        $this->file = $this->directory.DIRECTORY_SEPARATOR.CanonicalJson::hash($body).'.json';
        $result = ['content' => '{"client_intent":"answer_only","slot_updates":[]}', 'toolCalls' => [], 'usage' => ['promptTokens' => 5, 'completionTokens' => 3], 'model' => $request->model, 'latencyMs' => 1, 'costMicros' => 0];
        file_put_contents($this->file, json_encode(['result' => $result], JSON_THROW_ON_ERROR));

        return $this->delegate->complete($request, $context);
    }
}

final class ExtractorGatewayIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_extractor_reaches_real_gateway_fake_driver_and_persists_usage_without_network(): void
    {
        Http::fake();
        $org = (string) Str::ulid();
        $brand = (string) Str::ulid();
        DB::table('organizations')->insert(['id' => $org, 'name' => 'Test', 'status' => 'active', 'settings' => '{}']);
        DB::table('brands')->insert(['id' => $brand, 'organization_id' => $org, 'name' => 'Test', 'status' => 'active', 'theme' => 'neutral', 'settings' => '{}']);
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org, $brand, source: 'job');
        $knowledge = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $knowledge[$file] = Yaml::parseFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml'));
        }
        $snapshot = new KnowledgeSnapshot($knowledge, 'fixture', 'fixture');
        $directory = storage_path('app/engine-fixture-'.Str::random(20));
        mkdir($directory, 0700, true);
        config(['runtime.ai_driver' => 'fake', 'features.E0-05.defaults.fixture_directory' => $directory]);
        $gateway = new FixtureBackedGateway(app(AiGateway::class), $directory);
        try {
            app(TenantContextRunner::class)->run($tenant, function () use ($tenant, $snapshot, $gateway, $org, $brand): void {
                $prompt = app(ExtractorPromptLoader::class)->load($tenant);
                $engine = new Extractor($gateway, $snapshot, $prompt, 'google/gemini-2.5-flash', 0.0, 'Антон');
                $context = new AiContext($org, $brand, 'extractor', 'seller', 'brand', $brand, 'engine-integration', ['knowledge_public']);
                $first = $engine->extract(new LeadState, [], 'Hello', $context);
                $second = $engine->extract(new LeadState, [], 'Hello', $context);
                $this->assertSame('answer_only', $first['client_intent']);
                $this->assertSame($first, $second);
                $this->assertSame(1, DB::table('ai_calls')->where('purpose', 'extractor')->count());
                $this->assertSame(1, DB::table('object_costs')->count());
            });
            Http::assertNothingSent();
        } finally {
            if ($gateway->file !== null && is_file($gateway->file)) {
                unlink($gateway->file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
