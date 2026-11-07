<?php

namespace Tests\Feature\Seller\Engine;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** D-4.9 «Что дальше»: выбранное для закрытой темы действие применяется в защищённом ходе диалога. */
final class ClosedTopicNextActionE410LTest extends TestCase
{
    private function snapshot(array $topics): KnowledgeSnapshot
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(base_path('tests/Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml'));
        }
        $data['closed_topics'] = $topics;

        return new KnowledgeSnapshot($data, 'release', 'checksum');
    }

    private function x(array $o = []): array
    {
        return $o + ['client_intent' => 'closed_topic', 'slot_updates' => [], 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral',
            'wants_only_prices' => false, 'asked_if_bot' => false, 'wants_human' => false, 'do_not_disturb' => false, 'off_topic' => false,
            'prompt_injection' => false, 'unknown_question' => null, 'client_claims' => [], 'confidence' => 0.9];
    }

    public function test_return_topic_bridges_back_to_the_order(): void
    {
        $snapshot = $this->snapshot([['id' => 'legal_advice', 'category' => 'legal', 'idea' => 'не советуем обходные схемы', 'next' => 'return']]);
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $policy = new Policy($snapshot);
        $x = $this->x(['closed_topic' => 'legal_advice']);
        $policy->applyExtraction($state, $x);
        $goal = $policy->decide($state, $x);

        self::assertSame('closed_topic', $goal->kind);
        self::assertNotNull($goal->slot);
        self::assertNotSame('handoff', $state->stage());
        self::assertFalse($state->flag('escalate'));
    }

    public function test_handoff_topic_passes_the_lead_to_a_manager(): void
    {
        $snapshot = $this->snapshot([['id' => 'legal_advice', 'category' => 'legal', 'idea' => 'передам ваш запрос менеджеру', 'next' => 'handoff']]);
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $policy = new Policy($snapshot);
        $x = $this->x(['closed_topic' => 'legal_advice']);
        $policy->applyExtraction($state, $x);
        $goal = $policy->decide($state, $x);

        self::assertSame('handoff', $goal->kind);
        self::assertSame('handoff', $state->stage());
        self::assertTrue($state->flag('escalate'));
        self::assertSame('closed_topic', $state->flag('escalate_reason'));
        self::assertStringContainsString('передам ваш запрос менеджеру', $goal->extras['message_idea']);

        $second = $policy->decide($state, $x);
        self::assertSame('handoff', $second->kind);
    }

    public function test_missing_next_field_defaults_to_return(): void
    {
        $snapshot = $this->snapshot([['id' => 'legal_advice', 'category' => 'legal', 'idea' => 'без обходных схем']]);
        $state = new LeadState;
        $state->setFlag('introduced', true);
        $goal = (new Policy($snapshot))->decide($state, $this->x(['closed_topic' => 'legal_advice']));

        self::assertSame('closed_topic', $goal->kind);
    }
}
