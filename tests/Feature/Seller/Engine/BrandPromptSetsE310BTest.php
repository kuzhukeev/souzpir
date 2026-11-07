<?php

namespace Tests\Feature\Seller\Engine;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\BrandPromptSets;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Database\Seeders\Ai\PromptSetsSeeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Tenancy\TenancyTestCase;

final class BrandPromptSetsE310BTest extends TenancyTestCase
{
    private function knowledge(string $company, string $intro): KnowledgeSnapshot
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = ['company' => $company, 'intro' => $intro, 'name' => 'Мария'];

        return new KnowledgeSnapshot($data, 'release', 'checksum');
    }

    public function test_original_and_other_brand_use_separate_verified_versions(): void
    {
        [, $org, $first, $second] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $legacy = new TenantContext($org->id, $first->id, source: 'job');
        $other = new TenantContext($org->id, $second->id, source: 'job');
        $sets = app(BrandPromptSets::class);

        foreach (BrandPromptSets::NAMES as $name) {
            $original = $sets->load($legacy, $this->knowledge('Союз Пир', 'Продажа пиротехники'), $name);
            $current = $sets->load($other, $this->knowledge('Сад и Дом', 'Садовые инструменты, Казань'), $name);
            $this->assertSame('v1', $original->version);
            $this->assertSame('v2', $current->version);
            $this->assertSame($second->id, $current->brand_id);
            $this->assertStringNotContainsString('Москва', $current->body);
            $this->assertStringNotContainsString('Союз Пир', $current->body);
            $this->assertStringNotContainsString('пиротех', mb_strtolower($current->body));
        }
        $this->assertSame(6, DB::table('prompt_sets')->whereNull('brand_id')->count());
        $this->assertSame(0, DB::table('prompt_sets')->where('brand_id', $first->id)->count());
        $this->assertSame(5, DB::table('prompt_sets')->where('brand_id', $second->id)->count());
    }

    public function test_unknown_stage_and_modified_brand_prompt_are_rejected(): void
    {
        [, $org, , $brand] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $knowledge = $this->knowledge('Сад и Дом', 'Казань');
        $sets = app(BrandPromptSets::class);
        try {
            $sets->load($tenant, $knowledge, 'missing');
            $this->fail('Unknown stage accepted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $sets->load($tenant, $knowledge, 'extractor');
        DB::table('prompt_sets')->where('brand_id', $brand->id)->where('name', 'extractor')->update(['body' => 'tampered']);
        $this->expectException(LogicException::class);
        $sets->load($tenant, $knowledge, 'extractor');
    }

    public function test_other_brand_extractor_renders_its_persona_with_fake_gateway_and_rejects_missing_intents(): void
    {
        [, $org, , $brand] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        app(BrandPromptSets::class)->load($tenant, $this->knowledge('Сад и Дом', 'Садовые инструменты, Казань'), 'extractor');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, 'v2');
        $gateway = new class implements AiGateway
        {
            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                throw new LogicException('Live LLM must not be called.');
            }
        };
        $missing = new Extractor($gateway, $this->knowledge('Сад и Дом', 'Казань'), $prompt, 'fake-model', 0, 'Мария');
        try {
            $missing->buildRequest(new LeadState, [], 'Здравствуйте');
            $this->fail('Missing knowledge accepted.');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = ['company' => 'Сад и Дом', 'intro' => 'Садовые инструменты, Казань', 'name' => 'Мария'];
        $data['intents'] = [['id' => 'unclear', 'description' => 'Неясный запрос']];
        $knowledge = new KnowledgeSnapshot($data, 'release', 'checksum');
        $request = (new Extractor($gateway, $knowledge, $prompt, 'fake-model', 0, 'Мария'))
            ->buildRequest(new LeadState, [], 'Здравствуйте');
        $this->assertSame('v2', $request->promptVersion);
        $this->assertStringContainsString('Сад и Дом', $request->messages[0]['content']);
        $this->assertStringContainsString('Казань', $request->messages[0]['content']);
        $this->assertStringNotContainsString('пиротех', mb_strtolower($request->messages[0]['content']));
    }
}
