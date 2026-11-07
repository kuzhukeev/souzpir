<?php

namespace Tests\Feature\Seller\Engine;

use App\Contracts\Ai\AiGateway;
use App\Seller\Engine\Extractor;
use App\Seller\Engine\ExtractorPromptLoader;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\Import\YamlKnowledgeImporter;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextRunner;
use Database\Seeders\Ai\PromptSetsSeeder;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ClientFieldsE610ETest extends TenancyTestCase
{
    public function test_published_fields_are_extracted_saved_and_requested_by_stage(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(PromptSetsSeeder::class)->run();
        $base = app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $profiles = json_decode(file_get_contents(base_path('tests/fixtures/E6-10D/profiles.json')), true, 512, JSON_THROW_ON_ERROR);
        $values = [
            'service' => ['car_model' => 'Toyota Camry', 'car_year' => 2020, 'service_type' => 'Замена масла', 'preferred_date' => 'Пятница', 'branch' => 'Центральный'],
            'school' => ['course' => 'Английский', 'level' => 'Начальный', 'format' => 'Онлайн', 'preferred_time' => 'Вечером'],
        ];
        foreach ($values as $profile => $slots) {
            $data = $base->toArray();
            $data['policy']['client_fields'] = $profiles[$profile]['fields'];
            $knowledge = new KnowledgeSnapshot($data, $base->releaseId(), $base->checksum());
            $prompt = app(ExtractorPromptLoader::class)->load($tenant);
            $extractor = new Extractor(app(AiGateway::class), $knowledge, $prompt, 'fixture', 0.0, 'Антон');
            foreach ($profiles[$profile]['fields'] as $field) {
                self::assertSame($field['name'], $extractor->schema()['properties']['slot_updates']['properties'][$field['id']]['description']);
            }
            $state = new LeadState;
            $state->setStage('need');
            $policy = new Policy($knowledge);
            self::assertSame(array_key_first($slots), $policy->nextSlot($state));
            $first = array_key_first($slots);
            $state->setSlot($first, $slots[$first]);
            self::assertSame(array_keys($slots)[1], $policy->nextSlot($state));
            $parsed = $extractor->normalize(['client_intent' => 'unclear', 'slot_updates' => $slots]);
            self::assertTrue($extractor->valid($parsed));
            $policy->applyExtraction($state, $parsed);
            foreach ($slots as $id => $value) {
                self::assertSame($value, $state->slot($id));
                self::assertStringContainsString($id.': '.$value, $state->cardText());
            }
            self::assertNotContains(array_key_first($slots), array_column($policy->computeMissing($state), 'slot'));
        }
    }

    public function test_missing_required_stage_field_is_asked_before_advancing(): void
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['policy'] = ['client_fields' => [['id' => 'course', 'name' => 'Курс', 'type' => 'text', 'required' => true, 'stage' => 'need']], 'slot_order' => ['unknown' => []]];
        $policy = new Policy(new KnowledgeSnapshot($data, '', ''));
        $state = new LeadState;
        $state->setFlag('introduced', true);
        foreach (['city' => 'Москва', 'purpose' => 'на продажу', 'experience' => 'нет', 'budget_range' => '100', 'timing' => 'сегодня'] as $slot => $value) {
            $state->setSlot($slot, $value);
        }
        $policy->applyExtraction($state, []);
        self::assertSame('need', $state->stage());
        self::assertSame('course', $policy->nextSlot($state));
        $policy->applyExtraction($state, ['slot_updates' => ['course' => 'Английский']]);
        self::assertSame('close_contact', $state->stage());
    }

    public function test_brand_can_define_existing_non_contact_field(): void
    {
        [$owner, $org, $brand, , $membership] = $this->cabinet();
        $tenant = new TenantContext($org->id, $brand->id, $owner->id, $membership->id);
        app(PromptSetsSeeder::class)->run();
        $base = app(TenantContextRunner::class)->run($tenant, fn () => app(YamlKnowledgeImporter::class)
            ->importAndPublish($tenant, base_path('tests/Feature/Seller/Knowledge/Fixtures/data')));
        $data = $base->toArray();
        $data['policy']['client_fields'] = [['id' => 'city', 'name' => 'Филиал', 'type' => 'select', 'options' => ['Москва', 'Казань'], 'required' => true, 'stage' => 'qualify']];
        $knowledge = new KnowledgeSnapshot($data, $base->releaseId(), $base->checksum());
        $extractor = new Extractor(app(AiGateway::class), $knowledge, app(ExtractorPromptLoader::class)->load($tenant), 'fixture', 0.0, 'Антон');
        self::assertSame(['Москва', 'Казань', null], $extractor->schema()['properties']['slot_updates']['properties']['city']['enum']);
        self::assertSame('Филиал', $extractor->schema()['properties']['slot_updates']['properties']['city']['description']);
        $state = new LeadState;
        $state->setStage('qualify');
        self::assertSame('city', (new Policy($knowledge))->nextSlot($state));
        $state->setStage('close_contact');
        $goal = (new Policy($knowledge))->decide($state, ['client_intent' => 'ask_delivery']);
        self::assertSame('city', $goal->slot);
        self::assertStringContainsString('Филиал', $goal->instruction);
    }
}
