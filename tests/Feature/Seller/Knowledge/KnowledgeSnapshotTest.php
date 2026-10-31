<?php

namespace Tests\Feature\Seller\Knowledge;

use App\Seller\Knowledge\KnowledgeSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Seller\Knowledge\Fixtures\PrototypeKnowledge;

final class KnowledgeSnapshotTest extends TestCase
{
    public function test_snapshot_preserves_prototype_namespaces_and_accessors(): void
    {
        $prototype = new PrototypeKnowledge(__DIR__.'/Fixtures/data', 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $prototype->all($file);
        }
        $snapshot = new KnowledgeSnapshot($data, 'fixture-release', hash('sha256', 'fixture'));
        foreach (PrototypeKnowledge::FILES as $file) {
            self::assertSame($prototype->all($file), $snapshot->all($file), $file);
        }
        self::assertSame($data, $snapshot->toArray());
        self::assertSame($prototype->intentIds(), $snapshot->intentIds());
        self::assertSame($prototype->conditions(), $snapshot->conditions());
        self::assertSame($prototype->persona(), $snapshot->persona());
        self::assertSame($prototype->policy(), $snapshot->policy());
        self::assertSame($prototype->guardRules(), $snapshot->guardRules());
        self::assertSame($prototype->escalation(), $snapshot->escalation());
        self::assertSame($prototype->closedTopics(), $snapshot->closedTopics());
        self::assertSame($prototype->allFactSentences(), $snapshot->allFactSentences());
        self::assertSame($prototype->triggers(), $snapshot->triggers());
        self::assertSame($prototype->fewshot(['ask_city'], 'all'), $snapshot->fewshot(['ask_city'], 'all'));
        self::assertSame($prototype->badExamples(), $snapshot->badExamples());
        self::assertSame($prototype->resolvePlaceholders('Минимум {cond:min_order_rub}'), $snapshot->resolvePlaceholders('Минимум {cond:min_order_rub}'));
        self::assertSame('fixture-release', $snapshot->releaseId());
    }

    public function test_snapshot_rejects_mutable_objects_and_nonfinite_numbers(): void
    {
        $prototype = new PrototypeKnowledge(__DIR__.'/Fixtures/data', 0);
        $data = [];
        foreach (PrototypeKnowledge::FILES as $file) {
            $data[$file] = $prototype->all($file);
        }
        $data['persona']['object'] = (object) ['name' => 'mutable'];
        try {
            new KnowledgeSnapshot($data, 'fixture', 'checksum');
            self::fail('Mutable object accepted');
        } catch (\InvalidArgumentException) {
        }
        $data['persona']['object'] = INF;
        $this->expectException(\InvalidArgumentException::class);
        new KnowledgeSnapshot($data, 'fixture', 'checksum');
    }

    public function test_missing_namespace_is_not_an_empty_successful_snapshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KnowledgeSnapshot([], 'fixture-release', 'checksum');
    }
}
