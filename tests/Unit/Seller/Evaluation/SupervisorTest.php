<?php

namespace Tests\Unit\Seller\Evaluation;

use App\Seller\Evaluation\Supervisor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SupervisorTest extends TestCase
{
    public function test_five_layers_and_donor_issue_dictionary_are_in_schema(): void
    {
        $supervisor = new Supervisor;
        self::assertCount(5, Supervisor::LAYERS);
        self::assertCount(25, Supervisor::ISSUE_TYPES);
        self::assertSame(array_keys(Supervisor::ISSUE_TYPES), $supervisor->schema()['properties']['issues']['items']['properties']['type']['enum']);
    }

    public function test_transcript_keeps_intent_goal_tools_and_guard(): void
    {
        $text = (new Supervisor)->transcript([['client' => 'Есть доставка?', 'agent' => 'Уточните город.',
            'meta' => ['intent' => 'ask_delivery', 'confidence' => 0.93, 'goal' => 'ask_city', 'tools' => ['lookup'], 'guard' => 'ok']]]);
        self::assertStringContainsString('интент ask_delivery (0.93) | цель ask_city | tools: lookup | guard ok', $text);
        self::assertStringContainsString('Клиент: Есть доставка?', $text);
    }

    public function test_normalization_and_aggregation_reject_invalid_issue_layer(): void
    {
        $supervisor = new Supervisor;
        $valid = ['naturalness' => 4, 'goal' => 3, 'facts' => 5, 'objections' => 4, 'brevity' => 4,
            'comment' => 'Хороший ход', 'issues' => [['type' => 'repeated_question', 'layer' => 'policy',
                'severity' => 'medium', 'turn' => 1, 'quote' => 'Город?', 'explanation' => 'Повтор', 'fix' => 'Не повторять']]];
        $first = $supervisor->normalize($valid, 1);
        self::assertSame(4.0, $first['average']);
        $aggregate = $supervisor->aggregate([['id' => 'a', 'issues' => $first['issues']], ['id' => 'b', 'issues' => $first['issues']]]);
        self::assertSame(2, $aggregate['groups'][0]['dialogs']);
        self::assertSame(2, $aggregate['by_layer']['policy']);
        $valid['issues'][0]['layer'] = 'guard';
        $this->expectException(InvalidArgumentException::class);
        $supervisor->normalize($valid, 1);
    }
}
