<?php

namespace Tests\Unit\Shadow;

use App\Shadow\ShadowJudge;
use Tests\TestCase;

final class ShadowJudgeTest extends TestCase
{
    public function test_identical_responses_match_after_normalizing_whitespace_and_case(): void
    {
        [$verdict, $notes] = (new ShadowJudge)->compare('Здравствуйте,  чем помочь?', "здравствуйте, чем\nпомочь? ");
        self::assertSame('match', $verdict);
        self::assertNull($notes);
    }

    public function test_different_responses_mismatch_with_notes(): void
    {
        [$verdict, $notes] = (new ShadowJudge)->compare('Доставка займёт 3 дня.', 'Доставка займёт 5 дней.');
        self::assertSame('mismatch', $verdict);
        self::assertNotNull($notes);
    }
}
