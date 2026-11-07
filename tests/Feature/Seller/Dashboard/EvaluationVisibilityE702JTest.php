<?php

namespace Tests\Feature\Seller\Dashboard;

use App\Seller\Evaluation\EvaluationVisibility;
use App\Seller\Evaluation\ScenarioFallbackJudge;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;

final class EvaluationVisibilityE702JTest extends TestCase
{
    public function test_filter_excludes_only_test_simulation_and_preserves_other_brand_scope(): void
    {
        $db = new Manager;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = $db->getConnection();
        $connection->statement('create table seller_evaluations (id text, brand_id text, sim_run_id text, comment text)');
        $connection->table('seller_evaluations')->insert([
            ['id' => 'test', 'brand_id' => 'a', 'sim_run_id' => 'sim', 'comment' => ScenarioFallbackJudge::COMMENT],
            ['id' => 'normal', 'brand_id' => 'a', 'sim_run_id' => 'sim2', 'comment' => null],
            ['id' => 'real', 'brand_id' => 'a', 'sim_run_id' => null, 'comment' => ScenarioFallbackJudge::COMMENT],
            ['id' => 'other', 'brand_id' => 'b', 'sim_run_id' => 'sim3', 'comment' => null],
        ]);
        $query = $connection->table('seller_evaluations as e')->where('e.brand_id', 'a');
        EvaluationVisibility::excludeTestBaseline($query, 'e.');
        self::assertEqualsCanonicalizing(['normal', 'real'], $query->pluck('e.id')->all());
        $plain = $connection->table('seller_evaluations')->where('brand_id', 'a');
        EvaluationVisibility::excludeTestBaseline($plain);
        self::assertEqualsCanonicalizing(['normal', 'real'], $plain->pluck('id')->all());
        self::assertTrue(EvaluationVisibility::isTestBaseline('sim', ScenarioFallbackJudge::COMMENT));
        self::assertFalse(EvaluationVisibility::isTestBaseline(null, ScenarioFallbackJudge::COMMENT));
        self::assertFalse(EvaluationVisibility::isTestBaseline('sim', null));
    }
}
