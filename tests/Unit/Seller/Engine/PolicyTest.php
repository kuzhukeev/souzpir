<?php

namespace Tests\Unit\Seller\Engine;

use App\Seller\Engine\LeadState;
use App\Seller\Engine\Policy;
use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class PolicyTest extends TestCase
{
    private Policy $p;

    protected function setUp(): void
    {
        $data = [];
        foreach (KnowledgeSnapshot::FILES as $file) {
            $data[$file] = Yaml::parseFile(__DIR__.'/../../../Feature/Seller/Knowledge/Fixtures/data/'.$file.'.yaml');
        }
        $this->p = new Policy(new KnowledgeSnapshot($data, 'fixture', 'fixture'));
    }

    private function x(array $o = []): array
    {
        return $o + ['client_intent' => 'answer_only', 'slot_updates' => [], 'client_questions' => [], 'objections' => [], 'sentiment' => 'neutral',
            'wants_only_prices' => false, 'asked_if_bot' => false, 'wants_human' => false, 'do_not_disturb' => false, 'off_topic' => false,
            'prompt_injection' => false, 'closed_topic' => null, 'unknown_question' => null, 'client_claims' => [], 'confidence' => 0.9];
    }

    public function test_slot_order_for_newbie(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $this->p->applyExtraction($s, $this->x(['slot_updates' => ['city' => 'Воронеж']]));
        $this->assertSame('purpose', $this->p->nextSlot($s));
        $this->p->applyExtraction($s, $this->x(['slot_updates' => ['purpose' => 'на продажу', 'experience' => 'нет']]));
        $this->assertSame('newbie', $s->clientType());
        $this->assertSame('outlet_type', $this->p->nextSlot($s));
    }

    public function test_for_self_skips_outlet_and_experience(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $this->p->applyExtraction($s, $this->x(['slot_updates' => ['city' => 'Москва', 'purpose' => 'для себя']]));
        $this->assertSame('for_self', $s->clientType());
        $this->assertSame('categories', $this->p->nextSlot($s));
    }

    public function test_budget_asked_max_twice(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $this->p->applyExtraction($s, $this->x(['slot_updates' => ['city' => 'Тула', 'purpose' => 'на продажу', 'experience' => 'нет', 'outlet_type' => 'павильон', 'categories' => ['всё']]]));
        $this->assertSame('budget_range', $this->p->nextSlot($s));
        $s->markAsked('budget_range');
        $s->inc('budget_asks');
        $this->assertSame('budget_range', $this->p->nextSlot($s), 'вторая попытка с вилкой');
        $s->inc('budget_asks');
        $this->assertNotSame('budget_range', $this->p->nextSlot($s), 'третьей попытки нет');
    }

    public function test_prices_only_branch(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $this->p->applyExtraction($s, $this->x(['wants_only_prices' => true, 'client_intent' => 'want_prices_only']));
        $this->assertSame('prices_only', $s->clientType());
        $this->assertSame('budget_range', $this->p->nextSlot($s));
    }

    public function test_do_not_disturb_ends_dialog(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $x = $this->x(['do_not_disturb' => true, 'client_intent' => 'opt_out']);
        $this->p->applyExtraction($s, $x);
        $g = $this->p->decide($s, $x);
        $this->assertSame('opt_out', $g->kind);
        $this->assertSame('declined', $this->p->computeOutcome($s));
    }

    public function test_off_topic_three_times_is_spam(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $x = $this->x(['off_topic' => true, 'client_intent' => 'off_topic']);
        $this->p->applyExtraction($s, $x);
        $this->assertSame('decline_off_topic', $this->p->decide($s, $x)->kind);
        $this->p->applyExtraction($s, $x);
        $this->p->applyExtraction($s, $x);
        $this->assertSame('silent', $this->p->decide($s, $x)->kind);
        $this->assertSame('spam', $this->p->computeOutcome($s));
    }

    public function test_closed_topic_goal(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $x = $this->x(['client_intent' => 'closed_topic', 'closed_topic' => 'management_names']);
        $this->p->applyExtraction($s, $x);
        $g = $this->p->decide($s, $x);
        $this->assertSame('closed_topic', $g->kind);
        $this->assertNotContains('log_unknown_question', $g->allowedTools);
    }

    public function test_deferred_insist_escalates(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $x = $this->x(['client_intent' => 'ask_deferred', 'objections' => ['deferred']]);
        $kinds = [];
        for ($i = 0; $i < 3; $i++) {
            $this->p->applyExtraction($s, $x);
            $kinds[] = $this->p->decide($s, $x)->kind;
        }
        $this->assertContains('handoff', $kinds, implode(',', $kinds));
    }

    public function test_tools_by_stage(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $this->assertNotContains('send_price', $this->p->toolsFor($s));
        $s->setStage('close_contact');
        $this->assertNotContains('send_price', $this->p->toolsFor($s), 'без контакта прайс не отправить');
        $s->setSlot('email', 'a@b.ru');
        $this->assertContains('send_price', $this->p->toolsFor($s));
    }

    public function test_full_contact_outcome_and_missing(): void
    {
        $s = new LeadState;
        $s->setFlag('introduced', true);
        $this->p->applyExtraction($s, $this->x(['slot_updates' => ['city' => 'Казань', 'purpose' => 'на продажу', 'experience' => 'много лет', 'email' => 'a@b.ru', 'next_contact_at' => 'завтра']]));
        $s->setFlag('price_sent', true);
        $this->assertSame('full_contact', $this->p->computeOutcome($s));
        $missing = array_column($this->p->computeMissing($s), 'slot');
        $this->assertContains('budget_range', $missing);
        $this->assertNotContains('email', $missing);
    }

    public function test_evasive_answer_allows_second_ask_but_refusal_does_not(): void
    {
        $s = new LeadState;
        $s->markAsked('outlet_type');
        $this->assertFalse($s->wasAsked('outlet_type'), 'после одного захода мягкий слот можно спросить ещё раз');
        $s->markAsked('outlet_type');
        $this->assertTrue($s->wasAsked('outlet_type'));

        $s2 = new LeadState;
        $s2->markAsked('categories');
        $s2->refuseSlot('categories');
        $this->assertTrue($s2->wasAsked('categories'), 'явный отказ — больше не спрашиваем');

        $s3 = new LeadState;
        $s3->markAsked('current_supplier');
        $this->assertTrue($s3->wasAsked('current_supplier'), 'поставщика спрашиваем один раз');
    }
}
