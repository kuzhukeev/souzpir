<?php

namespace Tests\Unit\Vision\Core;

use App\Vision\Core\Metrics;
use PHPUnit\Framework\TestCase;

class AuditMetricsTest extends TestCase
{
    public function test_unknown_readiness_is_interval_not_zero(): void
    {
        $r = Metrics::readiness([['score' => 1], ['score' => 0], ['score' => .5]]);
        $this->assertEquals(7.5, $r['lower']);
        $this->assertEquals(92.5, $r['upper']);
        $this->assertEquals(15, $r['coverage']);
    }

    public function test_macro_and_micro_are_distinct_and_missing_services_are_not_negative(): void
    {
        $a = array_fill(0, 10, ['weight' => .1, 'valid' => true, 'positive' => true]);
        $b = array_fill(0, 30, ['weight' => 1 / 30, 'valid' => true, 'positive' => false]);
        $r = Metrics::visibility([['cells' => $a], ['cells' => $b]]);
        $this->assertEquals(50, $r['macro_percent']);
        $this->assertEquals(25, $r['micro_percent']);
        $this->assertEquals(20, $r['coverage']);
        $this->assertEquals(10, $r['lower']);
        $this->assertEquals(90, $r['upper']);
        $this->assertSame(2, $r['available_services']);
        $this->assertNull(Metrics::visibility([])['macro_percent']);
    }

    public function test_one_partial_observation_per_service_does_not_claim_full_coverage(): void
    {
        $r = Metrics::visibility(array_fill(0, 10, ['cells' => [['weight' => .025, 'valid' => true, 'positive' => true]]]));
        $this->assertSame('partial', $r['status']);
        $this->assertSame('available', $r['macro_kind']);
        $this->assertEquals(2.5, $r['coverage']);
    }

    public function test_single_model_understanding_keeps_other_nine_services_unknown(): void
    {
        $criteria = [];
        for ($i = 1; $i <= 16; $i++) {
            $criteria[] = ['id' => $i, 'score' => 1];
        }for ($i = 17; $i <= 20; $i++) {
            $criteria[] = ['id' => $i, 'score' => null, 'lower' => .1, 'unknown_weight' => .9];
        }
        $r = Metrics::readiness($criteria);
        $this->assertEquals(82, $r['lower']);
        $this->assertEquals(100, $r['upper']);
        $this->assertEquals(82, $r['coverage']);
    }

    public function test_newly_observed_criteria_are_not_reported_as_weekly_improvement(): void
    {
        $r = Metrics::pairedReadiness([['id' => 1, 'score' => 1], ['id' => 2, 'score' => null]], [['id' => 1, 'score' => 1], ['id' => 2, 'score' => 1]]);
        $this->assertSame([1], $r['criterion_ids']);
        $this->assertEquals(0, $r['delta']);
        $this->assertSame(5, $r['coverage']);
    }

    public function test_percentile_counts_numeric_ties_and_panel_uses_common_mask(): void
    {
        $this->assertEquals(50, Metrics::percentile(10.0, [5, 10, 15]));
        $r = Metrics::panel([['id' => 1, 'score' => 1], ['id' => 2, 'score' => 1]], [['name' => 'Peer', 'website' => 'https://peer.example', 'criteria' => [['id' => 1, 'score' => 1], ['id' => 2, 'score' => null]]]]);
        $this->assertSame([1], $r['criterion_ids']);
        $this->assertEquals(5, $r['brand_score']);
        $this->assertSame(1, $r['rank_min']);
        $this->assertSame(2,$r['rank_max']);
        $this->assertEquals(50,$r['percentile']);
    }
}
