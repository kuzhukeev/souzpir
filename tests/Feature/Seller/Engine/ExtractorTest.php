<?php

namespace Tests\Feature\Seller\Engine;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPrompt;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CaptureGateway implements AiGateway
{
    public array $requests = [];

    public ?AiResult $result = null;

    public function complete(AiRequest $request, AiContext $context): AiResult
    {
        $this->requests[] = [$request, $context];

        return $this->result ?? new AiResult(null, [], [], $request->model, 0, null, 'fake-call', ['code' => 'fake_offline', 'retryable' => false]);
    }
}

final class ExtractorTest extends TenancyTestCase
{
    private function snapshot(): KnowledgeSnapshot
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml'));
        }

        return new KnowledgeSnapshot($data, 'release-fixture', 'checksum-fixture');
    }

    private function context($org, $brand, string $purpose = 'extractor'): AiContext
    {
        return new AiContext($org->id, $brand->id, $purpose, 'seller', 'conversation', (string) Str::ulid(), 'fixture-key', ['conversation']);
    }

    public function test_all_five_original_extractor_prompt_snapshots_and_history(): void
    {
        [, $org, $brand] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant);
        $knowledge = $this->snapshot();
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $knowledge, $prompt, 'google/gemini-2.5-flash', 0.0, 'Антон');
        $states = json_decode(file_get_contents(base_path('tests/fixtures/prototype/prompts/states.json')), true, 512, JSON_THROW_ON_ERROR);
        $extraction = ['slot_updates' => [], 'client_intent' => 'answer_only', 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral', 'wants_only_prices' => false, 'asked_if_bot' => false, 'wants_human' => false, 'do_not_disturb' => false, 'off_topic' => false, 'prompt_injection' => false, 'closed_topic' => null, 'unknown_question' => null, 'client_claims' => [], 'refused_slots' => [], 'confidence' => 0.9];
        foreach ($states as $name => $data) {
            $state = new LeadState($data);
            (new Policy($knowledge))->decide($state, $extraction, $name === 'opening');
            $history = [];
            for ($i = 0; $i < 8; $i++) {
                $history[] = ['role' => $i % 2 ? 'agent' : 'client', 'text' => 'history-'.$i];
            }
            $result = $extractor->extract($state, $history, 'sample input', $this->context($org, $brand));
            [$request] = $gateway->requests[array_key_last($gateway->requests)];
            $expected = file_get_contents(base_path('tests/fixtures/prototype/prompts/'.$name.'/extractor.txt'));
            $this->assertSame(str_replace("\r\n", "\n", $expected), str_replace("\r\n", "\n", $request->messages[0]['content']), $name);
            $this->assertCount(8, $request->messages);
            $this->assertSame('history-2', $request->messages[1]['content']);
            $this->assertSame('assistant', $request->messages[2]['role']);
            $this->assertSame($prompt->checksum, $request->promptChecksum);
            $this->assertSame('v1', $request->promptVersion);
            $this->assertSame('fake_offline', $result['_meta']['error']);
        }
    }

    public function test_normalization_validation_and_failure_fallback_match_prototype(): void
    {
        [, $org, $brand] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant);
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $this->snapshot(), $prompt, 'test-model', 0.0, 'Антон');
        $data = $extractor->normalize(['client_intent' => 'unknown', 'objections' => ['deferred', 'invented']]);
        $this->assertSame('unclear', $data['client_intent']);
        $this->assertSame(['deferred'], $data['objections']);
        $this->assertTrue($extractor->valid($data));
        $this->assertFalse($extractor->valid(array_merge($data, ['confidence' => 3])));
        $gateway->result = new AiResult(json_encode(['slot_updates' => [], 'client_intent' => 'answer_only']), [], ['promptTokens' => 1], 'test-model', 10, 1000, 'id');
        $result = $extractor->extract(new LeadState, [], 'hello', $this->context($org, $brand));
        $this->assertSame('answer_only', $result['client_intent']);
        $this->assertSame(0.001, $result['_meta']['cost_usd']);
        $gateway->result = new AiResult('{invalid', [], [], 'test-model', 10, null, 'id');
        $result = $extractor->extract(new LeadState, [], 'Пожалуйста, не пишите', $this->context($org, $brand));
        $this->assertSame('opt_out', $result['client_intent']);
        $this->assertTrue($result['do_not_disturb']);
    }

    public function test_prompt_source_is_tenant_bound_and_untrusted_blade_cannot_execute(): void
    {
        [, $org, $a, $b] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenantA = new TenantContext($org->id, $a->id, source: 'job');
        $tenantB = new TenantContext($org->id, $b->id, source: 'job');
        $loader = app(ExtractorPromptLoader::class);
        $prompt = $loader->load($tenantA);
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $this->snapshot(), $prompt, 'test-model', 0.0, 'Антон');
        try {
            $extractor->extract(new LeadState, [], 'hello', $this->context($org, $b));
            $this->fail('Foreign tenant accepted');
        } catch (LogicException) {
            $this->assertCount(0, $gateway->requests);
        }
        $malicious = '@php(throw new \\RuntimeException("executed"))';
        DB::table('prompt_sets')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $b->id, 'name' => 'extractor', 'version' => 'v1', 'body' => $malicious, 'checksum' => hash('sha256', $malicious), 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $loader->load($tenantB);
            $this->fail('Untrusted template accepted');
        } catch (LogicException $e) {
            $this->assertStringContainsString('trusted source', $e->getMessage());
        }
        $reflection = new \ReflectionClass(ExtractorPrompt::class);
        $forged = $reflection->newInstanceWithoutConstructor();
        foreach (['organizationId' => $org->id, 'brandId' => $b->id, 'version' => 'v1', 'checksum' => hash('sha256', $malicious), 'body' => $malicious] as $property => $value) {
            $reflection->getProperty($property)->setValue($forged, $value);
        }
        $forgedExtractor = new Extractor($gateway, $this->snapshot(), $forged, 'test-model', 0.0, 'Anton');
        try {
            $forgedExtractor->extract(new LeadState, [], 'hello', $this->context($org, $b));
            $this->fail('Forged prompt was executed');
        } catch (LogicException $e) {
            $this->assertStringContainsString('trusted source', $e->getMessage());
            $this->assertCount(0, $gateway->requests);
        }
        try {
            ExtractorPrompt::verified($tenantB, 'v1', hash('sha256', $malicious), $malicious);
            $this->fail('Template verification bypassed');
        } catch (LogicException $e) {
            $this->assertStringContainsString('trusted source', $e->getMessage());
        }
    }
}
