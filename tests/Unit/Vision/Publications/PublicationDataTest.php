<?php

namespace Tests\Unit\Vision\Publications;

use App\Vision\Publications\PublicationData;
use PHPUnit\Framework\TestCase;

final class PublicationDataTest extends TestCase
{
    public function test_only_aggregate_values_leave_internal_report(): void
    {
        $result = (new PublicationData)->fromPayload([
            'questions' => [['id' => 'secret-question', 'text' => 'Личные данные']],
            'reviewed_facts' => [['text' => 'private']],
            'metrics' => ['visibility' => ['macro_percent' => 42.125, 'coverage' => 85],
                'panel' => ['share_of_voice' => 18, 'position_average' => 2.5], 'internal_prompt' => 'hidden'],
        ]);

        self::assertSame(['visibility' => 42.13, 'coverage' => 85.0, 'share_of_voice' => 18.0,
            'position' => 2.5, 'questions' => 1], $result);
        self::assertStringNotContainsString('Личные данные', json_encode($result));
    }
}
