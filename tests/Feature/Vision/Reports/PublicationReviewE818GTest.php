<?php

namespace Tests\Feature\Vision\Reports;

use App\Vision\Core\VisionReportReader;
use App\Vision\Publications\PublicationData;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class PublicationReviewE818GTest extends TestCase
{
    public function test_only_selected_verified_question_proposals_enter_new_public_payload(): void
    {
        $source = ['questions' => [['id' => 'q1', 'text' => 'Вопрос'], ['id' => 'q2', 'text' => 'Другой']],
            'metrics' => ['questions' => ['q1' => ['planned' => 2, 'mention_denominator' => 2, 'mentioned' => 0],
                'q2' => ['planned' => 2, 'mention_denominator' => 1, 'mentioned' => 0]], 'slots' => []],
            'recommendations' => ['items' => []]];
        $original = $source;
        $reader = Mockery::mock(app(VisionReportReader::class));
        $reader->shouldReceive('read')->with('report')->times(3)->andReturn($source);
        $this->app->instance(VisionReportReader::class, $reader);
        $data = app(PublicationData::class);
        self::assertSame([['key' => 'action:0', 'title' => 'Изучить ответы на этот вопрос',
            'why' => 'Проверьте сохранённые ответы; причины не установлены.', 'question_id' => 'q1']], $data->candidatesFromPayload($source));
        self::assertSame([], $data->fromReport('report', null, [])['actions']);
        $public = $data->fromReport('report', null, ['action:0']);
        self::assertSame(['Изучить ответы на этот вопрос'], $public['actions']);
        self::assertSame(['q1'], $public['action_question_ids']);
        try {
            $data->fromReport('report', null, ['action:9']);
            self::fail('Unknown review key was accepted.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('confirmed_actions', $e->errors());
        }
        self::assertSame($original, $source);
    }
}
