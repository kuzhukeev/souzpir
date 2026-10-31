<?php

namespace Tests\Feature\Seller\Engine;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Conversations\Models\Conversation;
use App\Seller\Engine\EngineFactory;
use App\Seller\Engine\ExtractionPending;
use App\Seller\Engine\ExtractionUncertain;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPrompt;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class CaptureGateway implements AiGateway
{
    public array $requests = [];

    public ?AiResult $result = null;

    public function complete(AiRequest $request, AiContext $context): AiResult
    {
        $this->requests[] = [$request, $context];

        return $this->result ?? new AiResult(null, [], [], $request->model, 0, null, 'fake-call', ['code' => 'empty_response', 'retryable' => false]);
    }
}

final class ExtractorTest extends TenancyTestCase
{
    private function snapshot($owner, $org, $brand, $membership): KnowledgeSnapshot
    {
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);

        return app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
    }

    private function context($org, $brand, string $purpose = 'extractor'): AiContext
    {
        $conversation = $this->within($org, $brand, fn () => Conversation::create(['state' => []]));

        return new AiContext($org->id, $brand->id, $purpose, 'seller', 'conversation', $conversation->id, 'fixture-key', ['conversation_content', 'client_profile', 'knowledge_internal']);
    }

    private function extract(Extractor $extractor, LeadState $state, array $history, string $message, AiContext $context): array
    {
        return app(TenantContextRunner::class)->run(new TenantContext($context->organizationId, $context->brandId, source: 'job'), fn () => $extractor->extract($state, $history, $message, $context));
    }

    public function test_all_five_original_extractor_prompt_snapshots_and_history(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant);
        $knowledge = $this->snapshot($owner, $org, $brand, $membership);
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
            $result = $this->extract($extractor, $state, $history, 'sample input', $this->context($org, $brand));
            [$request] = $gateway->requests[array_key_last($gateway->requests)];
            $expected = file_get_contents(base_path('tests/fixtures/prototype/prompts/'.$name.'/extractor.txt'));
            $this->assertSame(str_replace("\r\n", "\n", $expected), str_replace("\r\n", "\n", $request->messages[0]['content']), $name);
            $this->assertCount(8, $request->messages);
            $this->assertSame('history-2', $request->messages[1]['content']);
            $this->assertSame('assistant', $request->messages[2]['role']);
            $this->assertSame($prompt->checksum, $request->promptChecksum);
            $this->assertSame('v1', $request->promptVersion);
            $this->assertSame('empty_response', $result['_meta']['error']);
            $this->assertArrayNotHasKey('email', $result['slot_updates']);
        }
    }

    public function test_normalization_validation_and_failure_fallback_match_prototype(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant);
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $this->snapshot($owner, $org, $brand, $membership), $prompt, 'test-model', 0.0, 'Антон');
        $data = $extractor->normalize(['client_intent' => 'unknown', 'objections' => ['deferred', 'invented']]);
        $this->assertSame('unclear', $data['client_intent']);
        $this->assertSame(['deferred'], $data['objections']);
        $this->assertTrue($extractor->valid($data));
        $this->assertFalse($extractor->valid(array_merge($data, ['confidence' => 3])));
        $gateway->result = new AiResult(json_encode(['slot_updates' => [], 'client_intent' => 'answer_only']), [], ['promptTokens' => 1], 'test-model', 10, 1000, 'id');
        $result = $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $brand));
        $this->assertSame('answer_only', $result['client_intent']);
        $this->assertSame(0.001, $result['_meta']['cost_usd']);
        $gateway->result = new AiResult('{invalid', [], [], 'test-model', 10, null, 'id');
        $result = $this->extract($extractor, new LeadState, [], 'Пожалуйста, не пишите', $this->context($org, $brand));
        $this->assertSame('opt_out', $result['client_intent']);
        $this->assertTrue($result['do_not_disturb']);
    }

    public function test_prompt_source_is_tenant_bound_and_untrusted_blade_cannot_execute(): void
    {
        [$owner, $org, $a, $b, $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenantA = new TenantContext($org->id, $a->id, source: 'job');
        $tenantB = new TenantContext($org->id, $b->id, source: 'job');
        $loader = app(ExtractorPromptLoader::class);
        $prompt = $loader->load($tenantA);
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $this->snapshot($owner, $org, $a, $membership), $prompt, 'test-model', 0.0, 'Антон');
        try {
            $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $b));
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
        $snapshotB = $this->snapshot($owner, $org, $b, $membership);
        $forgedExtractor = new Extractor($gateway, $snapshotB, $forged, 'test-model', 0.0, 'Anton');
        try {
            $this->extract($forgedExtractor, new LeadState, [], 'hello', $this->context($org, $b));
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

    public function test_foreign_release_rejected_and_forged_data_with_real_checksum_ignored(): void
    {
        [$owner, $org, $a, $b, $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $real = $this->snapshot($owner, $org, $a, $membership);
        $data = $real->toArray();
        $data['intents'][0]['description'] = 'FORGED_SECRET_TEXT';
        $forged = new KnowledgeSnapshot($data, $real->releaseId(), $real->checksum());
        $tenantA = new TenantContext($org->id, $a->id, source: 'job');
        $tenantB = new TenantContext($org->id, $b->id, source: 'job');
        $loader = app(ExtractorPromptLoader::class);
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $forged, $loader->load($tenantA), 'test-model', 0.0, 'Anton');
        $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $a));
        $this->assertStringNotContainsString('FORGED_SECRET_TEXT', $gateway->requests[0][0]->messages[0]['content']);
        $components = app(TenantContextRunner::class)->run($tenantA, fn () => app(EngineFactory::class)->forRelease($tenantA, $real->releaseId(), $gateway, 'test-model', 0.0, 'Anton'));
        $this->assertInstanceOf(Policy::class, $components->policy);
        $other = new Extractor($gateway, $real, $loader->load($tenantB), 'test-model', 0.0, 'Anton');
        try {
            $this->extract($other, new LeadState, [], 'hello', $this->context($org, $b));
            $this->fail('Foreign release accepted');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertCount(1, $gateway->requests);
    }

    public function test_nonexistent_prompt_version_is_rejected_even_with_valid_body_and_checksum(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $knowledge = $this->snapshot($owner, $org, $brand, $membership);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $real = app(ExtractorPromptLoader::class)->load($tenant);
        $fakeVersion = ExtractorPrompt::verified($tenant, 'never-published', $real->checksum, $real->body);
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $knowledge, $fakeVersion, 'test-model', 0.0, 'Anton');
        try {
            $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $brand));
            $this->fail('Missing prompt version accepted');
        } catch (LogicException) {
            $this->assertCount(0, $gateway->requests);
        }
    }

    public function test_malformed_objections_do_not_cause_warning_or_exception_and_null_is_supported(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $knowledge = $this->snapshot($owner, $org, $brand, $membership);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $knowledge, app(ExtractorPromptLoader::class)->load($tenant), 'test-model', 0.0, 'Anton');
        foreach ([[['nested']], [(object) ['x' => 'deferred']], (object) ['x' => 'deferred'], 'bad', 3] as $bad) {
            $gateway->result = new AiResult(json_encode(['objections' => $bad]), [], [], 'test-model', 1, 0, 'id');
            $result = $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $brand));
            $this->assertSame('fallback', $result['_meta']['model']);
        }
        $gateway->result = new AiResult('{"objections":null}', [], [], 'test-model', 1, 0, 'id');
        $result = $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $brand));
        $this->assertSame([], $result['objections']);
        $this->assertSame('test-model', $result['_meta']['model']);
    }

    public function test_contact_is_locally_recovered_and_masks_and_ambiguous_emails_are_never_saved(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $knowledge = $this->snapshot($owner, $org, $brand, $membership);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $knowledge, app(ExtractorPromptLoader::class)->load($tenant), 'test-model', 0.0, 'Anton');
        $gateway->result = new AiResult(json_encode(['slot_updates' => ['email' => '[EMAIL]', 'messenger' => '[ТЕЛЕФОН]']]), [], [], 'test-model', 1, 0, 'id');
        $r = $this->extract($extractor, new LeadState, [], 'Write A.User+box@example.test, please', $this->context($org, $brand));
        $this->assertSame('A.User+box@example.test', $r['slot_updates']['email']);
        $this->assertNull($r['slot_updates']['messenger']);
        $r = $this->extract($extractor, new LeadState, [], 'a@example.test and b@example.test', $this->context($org, $brand));
        $this->assertNull($r['slot_updates']['email']);
        $r = $this->extract($extractor, new LeadState, [], 'a@example.test / A@example.test', $this->context($org, $brand));
        $this->assertSame('A@example.test', $r['slot_updates']['email']);
        $r = $this->extract($extractor, new LeadState(['slots' => ['email' => 'A@example.test']]), [], 'a@example.test', $this->context($org, $brand));
        $this->assertNull($r['slot_updates']['email']);
        $r = $this->extract($extractor, new LeadState, [], 'no address here', $this->context($org, $brand));
        $this->assertNull($r['slot_updates']['email']);
    }

    public function test_pending_and_uncertain_never_return_fallback(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $knowledge = $this->snapshot($owner, $org, $brand, $membership);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $knowledge, app(ExtractorPromptLoader::class)->load($tenant), 'test-model', 0.0, 'Anton');
        foreach (['in_progress', 'provider_uncertain', 'cost_unknown', 'unknown_new_status'] as $code) {
            $gateway->result = new AiResult(null, [], [], 'test-model', 1, null, 'id', ['code' => $code, 'retryable' => false]);
            try {
                $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $brand));
                $this->fail('Nonterminal fallback accepted');
            } catch (ExtractionPending|ExtractionUncertain $e) {
                $this->assertSame('id', $e->aiCallId);
                $this->assertSame($code === 'in_progress', $e instanceof ExtractionPending);
            }
        }
    }

    public function test_release_without_unclear_or_intents_fails_before_gateway(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $base = $this->snapshot($owner, $org, $brand, $membership);
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        $gateway = new CaptureGateway;
        $repo = app(KnowledgeRepository::class);
        foreach ([[], [['id' => 'other', 'description' => 'other']]] as $intents) {
            $data = $base->toArray();
            $data['intents'] = $intents;
            $release = app(TenantContextRunner::class)->run($tenant, function () use ($repo, $tenant, $data) {
                $current = DB::table('knowledge_releases')->where('brand_id', $tenant->brandId)->where('status', 'draft')->first();
                $draft = $repo->saveDraft($tenant, $current->id, $current->version, $data);

                return $repo->publish($tenant, $draft['id'], $draft['version']);
            });
            $prompt = app(ExtractorPromptLoader::class)->load($tenant);
            $extractor = new Extractor($gateway, $release, $prompt, 'test-model', 0.0, 'Anton');
            try {
                $this->extract($extractor, new LeadState, [], 'hello', $this->context($org, $brand));
                $this->fail('Incomplete intents accepted');
            } catch (LogicException) {
                $this->assertCount(0, $gateway->requests);
            }
        }
    }

    public function test_context_family_purpose_object_and_active_tenant_are_checked_before_gateway(): void
    {
        [$owner, $org, $brand, $other, $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $knowledge = $this->snapshot($owner, $org, $brand, $membership);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $gateway = new CaptureGateway;
        $extractor = new Extractor($gateway, $knowledge, app(ExtractorPromptLoader::class)->load($tenant), 'test-model', 0.0, 'Anton');
        $conversation = $this->within($org, $brand, fn () => Conversation::create(['state' => []]));
        $foreign = $this->within($org, $other, fn () => Conversation::create(['state' => []]));
        $classes = ['conversation_content', 'client_profile', 'knowledge_internal'];
        $contexts = [
            new AiContext($org->id, $brand->id, 'other', 'seller', 'conversation', $conversation->id, 'bad1', $classes),
            new AiContext($org->id, $brand->id, 'extractor', 'vision', 'conversation', $conversation->id, 'bad2', $classes),
            new AiContext($org->id, $brand->id, 'extractor', 'seller', 'brand', $brand->id, 'bad3', $classes),
            new AiContext($org->id, $brand->id, 'extractor', 'seller', 'conversation', $foreign->id, 'bad4', $classes),
            new AiContext($org->id, $brand->id, 'extractor', 'seller', 'conversation', (string) Str::ulid(), 'bad5', $classes),
            new AiContext($org->id, $brand->id, 'extractor', 'seller', 'conversation', $conversation->id, 'bad6', ['knowledge_internal']),
        ];
        foreach ($contexts as $context) {
            try {
                $this->extract($extractor, new LeadState, [], 'hello', $context);
                $this->fail('Invalid context accepted');
            } catch (LogicException) {
                $this->assertCount(0, $gateway->requests);
            }
        }
        $valid = new AiContext($org->id, $brand->id, 'extractor', 'seller', 'conversation', $conversation->id, 'bad-active', $classes);
        try {
            app(TenantContextRunner::class)->run(new TenantContext($org->id, $other->id, source: 'job'), fn () => $extractor->extract(new LeadState, [], 'hello', $valid));
            $this->fail('Foreign active tenant accepted');
        } catch (LogicException) {
            $this->assertCount(0, $gateway->requests);
        }
    }

    public function test_duplicate_or_invalid_intent_identifiers_are_rejected(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $snapshot = $this->snapshot($owner, $org, $brand, $membership);
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant);
        $method = new \ReflectionMethod(Extractor::class, 'assertIntents');
        foreach ([[['id' => 'unclear'], ['id' => 'unclear']], [['id' => 'unclear'], ['id' => '']], [['id' => 'unclear'], ['id' => 42]], [['id' => 'unclear'], []]] as $intents) {
            $data = $snapshot->toArray();
            $data['intents'] = $intents;
            $extractor = new Extractor(new CaptureGateway, new KnowledgeSnapshot($data, $snapshot->releaseId(), $snapshot->checksum()), $prompt, 'test', 0.0, 'Anton');
            try {
                $method->invoke($extractor);
                $this->fail('Invalid intent accepted');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
    }
}
