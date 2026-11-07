<?php

namespace Tests\Feature\Seller\Engine;

use App\Contracts\Ai\AiContext;
use App\Contracts\Ai\AiGateway;
use App\Contracts\Ai\AiRequest;
use App\Contracts\Ai\AiResult;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Database\Seeders\Ai\PromptSetsSeeder;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClientFieldsExtractionE610ETest extends TenancyTestCase
{
    private function snapshot(string $company, array $clientFields = []): KnowledgeSnapshot
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = ['company' => $company, 'intro' => 'Тест', 'name' => 'Мария'];
        $data['intents'] = [['id' => 'unclear', 'description' => 'Неясный запрос']];
        $data['policy'] = ['client_fields' => $clientFields];

        return new KnowledgeSnapshot($data, 'release', 'checksum');
    }

    private function fakeGateway(): AiGateway
    {
        return new class implements AiGateway
        {
            public function complete(AiRequest $request, AiContext $context): AiResult
            {
                return new AiResult(null, [], [], $request->model, 0, null, 'fake-call', ['code' => 'empty_response', 'retryable' => false]);
            }
        };
    }

    public function test_prototype_brand_schema_includes_wholesale_slots_and_published_fields(): void
    {
        [, $org, , $brand] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, 'v1');
        $fields = [['id' => 'car_model', 'name' => 'Марка и модель', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'need']];
        $extractor = new Extractor($this->fakeGateway(), $this->snapshot('Союз Пир', $fields), $prompt, 'fake-model', 0, 'Мария');
        $props = $extractor->schema()['properties']['slot_updates']['properties'];
        $this->assertArrayHasKey('city', $props);
        $this->assertArrayHasKey('budget_range', $props);
        $this->assertArrayHasKey('car_model', $props);
    }

    public function test_other_brand_schema_excludes_wholesale_slots_but_includes_published_fields(): void
    {
        [, $org, , $brand] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, 'v1');
        $fields = [
            ['id' => 'course', 'name' => 'Курс', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'need'],
            ['id' => 'level', 'name' => 'Уровень', 'type' => 'select', 'options' => ['Начальный', 'Продвинутый'], 'required' => true, 'stage' => 'need'],
        ];
        $extractor = new Extractor($this->fakeGateway(), $this->snapshot('Онлайн-школа', $fields), $prompt, 'fake-model', 0, 'Мария');
        $schema = $extractor->schema();
        $props = $schema['properties']['slot_updates']['properties'];
        $this->assertArrayNotHasKey('city', $props);
        $this->assertArrayNotHasKey('budget_range', $props);
        $this->assertArrayHasKey('email', $props);
        $this->assertArrayHasKey('course', $props);
        $this->assertSame(['Начальный', 'Продвинутый', null], $props['level']['enum']);
        $this->assertSame(['name', 'email', 'messenger', 'next_contact_at', 'course', 'level'], $schema['properties']['slot_updates']['required']);
    }

    public function test_other_brand_without_published_fields_keeps_only_contact_slots(): void
    {
        [, $org, , $brand] = $this->cabinet();
        app(PromptSetsSeeder::class)->run();
        $tenant = new TenantContext($org->id, $brand->id, source: 'job');
        $prompt = app(ExtractorPromptLoader::class)->load($tenant, 'v1');
        $extractor = new Extractor($this->fakeGateway(), $this->snapshot('Онлайн-школа'), $prompt, 'fake-model', 0, 'Мария');
        $schema = $extractor->schema();
        $this->assertSame(['name', 'email', 'messenger', 'next_contact_at'], array_keys($schema['properties']['slot_updates']['properties']));
    }

    public function test_next_slot_and_missing_include_required_published_fields(): void
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = ['company' => 'Онлайн-школа'];
        $data['policy'] = ['slot_order' => ['unknown' => []], 'client_fields' => [
            ['id' => 'course', 'name' => 'Курс', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'need'],
            ['id' => 'optional_note', 'name' => 'Заметка', 'type' => 'text', 'options' => [], 'required' => false, 'stage' => null],
        ]];
        $knowledge = new KnowledgeSnapshot($data, 'release', 'checksum');
        $policy = new Policy($knowledge);
        $state = new LeadState;
        $this->assertSame('course', $policy->nextSlot($state));
        $missing = $policy->computeMissing($state);
        $this->assertSame(['email', 'messenger', 'next_contact_at', 'course'], array_column($missing, 'slot'));
        $state->setSlot('course', 'Английский');
        $this->assertNull($policy->nextSlot($state));
        $state->setSlot('email', 'a@example.test');
        $state->setSlot('messenger', 'Telegram');
        $state->setSlot('next_contact_at', 'завтра');
        $this->assertSame([], $policy->computeMissing($state));
    }

    public function test_other_brand_stage_advances_past_need_only_once_required_field_is_filled(): void
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['persona'] = ['company' => 'Онлайн-школа'];
        $data['policy'] = ['slot_order' => ['unknown' => ['email', 'messenger', 'next_contact_at']], 'client_fields' => [
            ['id' => 'course', 'name' => 'Курс', 'type' => 'text', 'options' => [], 'required' => true, 'stage' => 'need'],
        ]];
        $knowledge = new KnowledgeSnapshot($data, 'release', 'checksum');
        $policy = new Policy($knowledge);
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $policy->applyExtraction($state, ['slot_updates' => []]);
        $this->assertSame('need', $state->stage(), 'Город/цель прототипа не должны блокировать неоптовый бренд.');
        $policy->applyExtraction($state, ['slot_updates' => ['course' => 'Английский', 'email' => 'a@example.test', 'messenger' => 'Telegram']]);
        $state->setFlag('price_sent', true);
        $policy->applyExtraction($state, ['slot_updates' => ['next_contact_at' => 'завтра в 12']]);
        $this->assertSame('followup', $state->stage());
    }
}
