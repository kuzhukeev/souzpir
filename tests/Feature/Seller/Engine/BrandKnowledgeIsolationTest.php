<?php

namespace Tests\Feature\Seller\Engine;

use App\Ai\Protected\ResponderProjection;
use App\Seller\Engine\ConfiguredDeliveryEstimate;
use App\Seller\Engine\Goal;
use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class BrandKnowledgeIsolationTest extends TestCase
{
    private function snapshot(bool $legacy): KnowledgeSnapshot
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml'));
        }
        if (! $legacy) {
            $data['persona'] = ['name' => 'Мария', 'company' => 'Пекарня', 'role' => 'менеджер', 'intro' => 'Пекарня в Твери', 'tone' => [], 'stop_phrases' => [], 'hard_bans' => [], 'slot_hints' => ['experience' => 'спросить про опыт пекаря']];
            $data['facts'] = [];
            $data['fewshot'] = [];
            $data['closed_topics'] = [];
        }

        return new KnowledgeSnapshot($data, $legacy ? 'legacy' : 'bakery', 'checksum');
    }

    public function test_policy_does_not_borrow_another_brand_industry_or_city(): void
    {
        $knowledge = $this->snapshot(false);
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $policy = new Policy($knowledge);
        $decline = $policy->decide($state, ['client_intent' => 'off_topic', 'off_topic' => true]);
        self::assertStringNotContainsString('пиротех', mb_strtolower($decline->instruction));
        $fresh = new LeadState;
        $fresh->setFlag('introduced', true);
        $next = $policy->decide($fresh, ['client_intent' => 'answer_only', 'slot_updates' => ['city' => 'Москва']]);
        self::assertSame([], $next->facts);
        self::assertSame(['status' => 'not_configured'], (new ConfiguredDeliveryEstimate)->calculate('Москва', null));
    }

    public function test_responder_does_not_add_legacy_industry_or_city_to_another_brand(): void
    {
        $data = $this->snapshot(true)->toArray();
        $data['persona']['company'] = 'Пекарня';
        $data['persona']['intro'] = 'Пекарня в Твери';
        $data['persona']['name'] = 'Мария';
        $data['persona']['hard_bans'] = [];
        $data['persona']['stop_phrases'] = [];
        $data['facts'] = [];
        $data['fewshot'] = [];
        $data['closed_topics'] = [];
        $knowledge = new KnowledgeSnapshot($data, 'bakery', 'checksum');
        $prompt = app(ResponderProjection::class)->render($knowledge, new LeadState, new Goal('answer_only'), 'Мария');
        self::assertStringContainsString('Пекарня', $prompt);
        self::assertStringNotContainsString('пиротех', mb_strtolower($prompt));
        self::assertStringNotContainsString('москва', mb_strtolower($prompt));
    }

    public function test_imported_local_delivery_is_taken_from_brand_facts(): void
    {
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $goal = (new Policy($this->snapshot(true)))->decide($state, ['client_intent' => 'answer_only', 'slot_updates' => ['city' => 'Москва']]);
        self::assertSame('delivery_moscow', $goal->facts[0]['id']);
        self::assertStringContainsString('ВДНХ', implode(' ', $goal->facts[0]['facts']));
    }

    public function test_pickup_aliases_and_fact_are_taken_from_the_selected_brand(): void
    {
        $data = $this->snapshot(false)->toArray();
        $data['policy']['pickup_fact_id'] = 'pickup_tver';
        $data['conditions']['delivery']['pickup_city_aliases'] = ['твер'];
        $data['facts'] = [['id' => 'pickup_tver', 'facts' => ['Самовывоз в Твери по адресу бренда.']]];
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $goal = (new Policy(new KnowledgeSnapshot($data, 'bakery', 'checksum')))->decide($state, ['client_intent' => 'answer_only', 'slot_updates' => ['city' => 'Тверь']]);
        self::assertSame('pickup_tver', $goal->facts[0]['id']);
        self::assertStringNotContainsString('ВДНХ', implode(' ', $goal->facts[0]['facts']));
    }
}
