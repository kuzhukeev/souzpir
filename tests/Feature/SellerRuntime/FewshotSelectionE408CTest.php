<?php

namespace Tests\Feature\SellerRuntime;

use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;

final class FewshotSelectionE408CTest extends TestCase
{
    public function test_selection_uses_threshold_limit_client_type_and_snapshot(): void
    {
        $data = array_fill_keys(KnowledgeSnapshot::FILES, []);
        $data['fewshot'] = ['examples' => [
            ...array_map(fn ($i) => ['tag' => 'city', 'text' => 'example-'.$i, 'client_type' => 'all', 'confidence' => 0.7], range(1, 7)),
            ['tag' => 'city', 'text' => 'below-threshold', 'client_type' => 'all', 'confidence' => 0.69],
            ['tag' => 'city', 'text' => 'another-client', 'client_type' => 'experienced', 'confidence' => 1],
        ], 'bad_examples' => [['text' => 'bad reply', 'why' => 'unsupported']]];
        $first = new KnowledgeSnapshot($data, 'brand-one-release', 'checksum');
        self::assertSame(array_map(fn ($i) => 'example-'.$i, range(1, 6)), array_column($first->fewshot(['city'], 'newbie', 6, 0.7), 'text'));
        self::assertSame('bad reply', $first->badExamples()[0]['text']);
        self::assertSame([], $first->fewshot(['other'], 'newbie', 6, 0.7));
        $data['fewshot'] = ['examples' => [['tag' => 'city', 'text' => 'second-brand', 'client_type' => 'all', 'confidence' => 0.9]]];
        $second = new KnowledgeSnapshot($data, 'brand-two-release', 'checksum');
        self::assertSame('second-brand', $second->fewshot(['city'], null, 6, 0.7)[0]['text']);
        self::assertSame('example-1', $first->fewshot(['city'], null, 6, 0.7)[0]['text']);
    }
}
