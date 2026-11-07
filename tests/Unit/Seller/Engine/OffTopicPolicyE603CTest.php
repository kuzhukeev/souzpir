<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class OffTopicPolicyE603CTest extends TestCase
{
    private function policy(array $overrides): Policy
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(__DIR__.'/../../../Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml');
        }
        $data['policy'] = array_replace_recursive($data['policy'], $overrides);

        return new Policy(new KnowledgeSnapshot($data, 'fixture', 'fixture'));
    }

    private function extraction(array $overrides): array
    {
        return $overrides + ['client_intent' => 'answer_only', 'slot_updates' => [], 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral', 'client_claims' => []];
    }

    public function test_published_off_topic_action_handoffs_at_its_threshold(): void
    {
        $policy = $this->policy(['limits' => ['off_topic_streak_silent' => 2], 'off_topic_action' => 'handoff']);
        $lead = new LeadState;
        $lead->setFlag('introduced', true);
        $input = $this->extraction(['client_intent' => 'off_topic', 'off_topic' => true]);
        $policy->applyExtraction($lead, $input);
        self::assertSame('decline_off_topic', $policy->decide($lead, $input)->kind);
        $policy->applyExtraction($lead, $input);
        self::assertSame('handoff', $policy->decide($lead, $input)->kind);
        self::assertSame('off_topic', $lead->flag('escalate_reason'));
    }

    public function test_default_and_separate_snapshots_keep_their_own_thresholds(): void
    {
        $default = $this->policy([]);
        $custom = $this->policy(['limits' => ['negative_streak_escalate' => 3]]);
        $offTopic = $this->extraction(['client_intent' => 'off_topic', 'off_topic' => true]);
        $negative = $this->extraction(['sentiment' => 'negative']);
        $defaultLead = new LeadState;
        $defaultLead->setFlag('introduced', true);
        for ($i = 0; $i < 3; $i++) {
            $default->applyExtraction($defaultLead, $offTopic);
        }
        self::assertSame('silent', $default->decide($defaultLead, $offTopic)->kind);
        $customLead = new LeadState;
        $customLead->setFlag('introduced', true);
        for ($i = 0; $i < 2; $i++) {
            $custom->applyExtraction($customLead, $negative);
        }
        self::assertNotSame('handoff', $custom->decide($customLead, $negative)->kind);
        $custom->applyExtraction($customLead, $negative);
        self::assertSame('handoff', $custom->decide($customLead, $negative)->kind);
        $anotherLead = new LeadState;
        $anotherLead->setFlag('introduced', true);
        for ($i = 0; $i < 2; $i++) {
            $default->applyExtraction($anotherLead, $negative);
        }
        self::assertSame('handoff', $default->decide($anotherLead, $negative)->kind);
    }
}
