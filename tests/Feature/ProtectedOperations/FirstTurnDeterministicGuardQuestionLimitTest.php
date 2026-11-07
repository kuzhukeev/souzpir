<?php

namespace Tests\Feature\ProtectedOperations;

use App\ProtectedOperations\FirstTurnDeterministicGuard;
use Tests\TestCase;

final class FirstTurnDeterministicGuardQuestionLimitTest extends TestCase
{
    public function test_third_consecutive_question_is_blocked(): void
    {
        $guard = new FirstTurnDeterministicGuard;
        $twoQuestionsThenOne = [
            ['text' => 'Какой у вас бюджет?', 'meta' => []],
            ['text' => 'Можем обсудить заказ?', 'meta' => []],
        ];
        self::assertTrue($guard->exceedsConsecutiveQuestionLimit($twoQuestionsThenOne, 'Что у вас по срокам?'));
        self::assertFalse($guard->exceedsConsecutiveQuestionLimit($twoQuestionsThenOne, 'Передали ваш запрос менеджеру.'));
    }

    public function test_second_consecutive_question_is_allowed(): void
    {
        $guard = new FirstTurnDeterministicGuard;
        $oneQuestion = [['text' => 'Можем обсудить заказ?', 'meta' => []]];
        self::assertFalse($guard->exceedsConsecutiveQuestionLimit($oneQuestion, 'Какой у вас бюджет?'));
    }

    public function test_non_question_reply_breaks_the_streak(): void
    {
        $guard = new FirstTurnDeterministicGuard;
        $answerThenTwoQuestions = [
            ['text' => 'Какой у вас бюджет?', 'meta' => []],
            ['text' => 'Отправили на почту прайс.', 'meta' => []],
            ['text' => 'Можем обсудить заказ?', 'meta' => []],
        ];
        self::assertFalse($guard->exceedsConsecutiveQuestionLimit($answerThenTwoQuestions, 'Что у вас по срокам?'));
    }

    public function test_reminders_do_not_count_and_do_not_break_the_streak(): void
    {
        $guard = new FirstTurnDeterministicGuard;
        $reminderBetweenTwoQuestions = [
            ['text' => 'Ждём вашего ответа?', 'meta' => ['goal' => ['kind' => 'followup_nudge']]],
            ['text' => 'Какой у вас бюджет?', 'meta' => []],
            ['text' => 'Напоминаем о заказе.', 'meta' => ['goal_kind' => 'followup_nudge']],
            ['text' => 'Можем обсудить заказ?', 'meta' => []],
        ];
        self::assertTrue($guard->exceedsConsecutiveQuestionLimit($reminderBetweenTwoQuestions, 'Что у вас по срокам?'));
    }
}
