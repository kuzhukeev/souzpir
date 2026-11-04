<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Seller\Evaluation\DraftScenarioPreview;
use App\Seller\Knowledge\KnowledgeSnapshot;
use Tests\TestCase;

final class DraftScenarioPreviewE401ITest extends TestCase
{
    public function test_preview_uses_draft_examples_and_keeps_unsupported_checks_failed(): void
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['policy'] = ['slot_order' => ['unknown' => ['city']]];
        $data['fewshot'] = ['examples' => [['tag' => 'open_greeting', 'text' => 'Добрый день! Чем помочь?', 'client_type' => 'all', 'confidence' => 1]]];
        $draft = new KnowledgeSnapshot($data, 'draft-id', 'checksum');
        $turn = app(DraftScenarioPreview::class)->turn($draft, 'Здравствуйте', ['one_question_per_turn', 'full_card']);
        self::assertSame('draft-id', $turn['meta']['draft_release_id']);
        self::assertTrue($turn['meta']['preview_only']);
        self::assertTrue($turn['meta']['expectation_checks']['one_question_per_turn']);
        self::assertFalse($turn['meta']['expectation_checks']['full_card']);
        self::assertNotNull($turn['meta']['goal']);
        self::assertSame('Добрый день! Чем помочь?', $turn['agent']);
        self::assertIsString($turn['meta']['guard_verdict']);
    }
}
