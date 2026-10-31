<?php

namespace Tests\Unit\Vision\Core;

use App\Vision\Core\VisibilityMetrics;
use Tests\TestCase;

class VisibilityMetricsTest extends TestCase
{
    public function test_rejects_unbounded_grid_before_iteration_without_overflow(): void
    {
        config(['features.E8-01.defaults.workload.max_slots' => 5]);
        $this->expectException(\InvalidArgumentException::class);
        (new VisibilityMetrics)->calculate([], ['provider'], 1, PHP_INT_MAX);
    }

    public function test_unreviewed_candidates_do_not_dilute_confirmed_competitor_share(): void
    {
        $slot = $this->slot('a', 1, 1, true);
        $slot['mentions'] = [['entity_id' => 'brand', 'name' => 'Brand', 'is_brand' => true, 'relevance' => 'confirmed'],
            ['entity_id' => 'candidate', 'name' => 'Pending', 'is_brand' => false, 'relevance' => 'candidate'],
            ['entity_id' => 'confirmed', 'name' => 'Confirmed', 'is_brand' => false, 'relevance' => 'confirmed']];
        $result = (new VisibilityMetrics)->calculate([$slot], ['a'], 1, 1);
        $this->assertSame(50.0, $result['panel']['share_of_voice']);
        $this->assertSame(2, $result['panel']['share_of_voice_denominator']);
        $this->assertNull(array_values(array_filter($result['entities'], fn ($entity) => $entity['name'] === 'Pending'))[0]['share_of_voice']);
    }

    public function test_four_provider_panel_can_be_fully_observed(): void
    {
        $slots = [];
        foreach (['a', 'b', 'c', 'd'] as $provider) {
            for ($q = 1; $q <= 20; $q++) {
                for ($k = 1; $k <= 2; $k++) {
                    $slots[] = $this->slot($provider, $q, $k, true);
                }
            }
        }
        $result = (new VisibilityMetrics)->calculate($slots, ['a', 'b', 'c', 'd'], 20, 2);
        $this->assertSame(160, $result['panel']['valid']);
        $this->assertSame(100.0, $result['visibility']['coverage']);
        $this->assertSame('full', $result['visibility']['macro_kind']);
        $this->assertSame(4, $result['visibility']['total_services']);
    }

    public function test_missing_and_invalid_answers_are_unknown_not_negative(): void
    {
        $invalid = $this->slot('a', 1, 1, true);
        $invalid['protocol_valid'] = false;
        $result = (new VisibilityMetrics)->calculate([$invalid], ['a'], 2, 2);
        $this->assertNull($result['panel']['mention_rate']);
        $this->assertSame(0, $result['panel']['valid']);
        $this->assertSame(1, $result['panel']['counts']['protocol_invalid']);
        $this->assertSame(3, $result['panel']['counts']['not_checked']);
        $this->assertSame(100.0, $result['panel']['unknown_bounds']['upper']);
        $this->assertNull($result['providers']['a']['stable_mention_rate']);
    }

    public function test_opposite_repeats_do_not_falsely_imply_stability(): void
    {
        $result = (new VisibilityMetrics)->calculate([$this->slot('a', 1, 1, true), $this->slot('a', 1, 2, false), $this->slot('a', 2, 1, false), $this->slot('a', 2, 2, true)], ['a'], 2, 2);
        $this->assertSame([50.0, 50.0], $result['providers']['a']['observed_run_range']);
        $this->assertSame(0.0, $result['providers']['a']['stable_mention_rate']);
        $this->assertSame(100.0, $result['providers']['a']['ever_mentioned_rate']);
    }

    public function test_partial_question_is_excluded_from_repeat_range(): void
    {
        $result = (new VisibilityMetrics)->calculate([$this->slot('a', 1, 1, false), $this->slot('a', 1, 2, false), $this->slot('a', 2, 1, true)], ['a'], 2, 2);
        $this->assertSame(33.33, $result['panel']['mention_rate']);
        $this->assertSame([0.0, 0.0], $result['providers']['a']['observed_run_range']);
        $this->assertSame(1, $result['providers']['a']['complete_questions']);
    }

    public function test_dynamics_use_paired_intersection_and_break_changed_series(): void
    {
        $metrics = new VisibilityMetrics;
        $before = [$this->slot('a', 1, 1, false), $this->slot('a', 2, 1, true)];
        $after = [$this->slot('a', 1, 1, true), $this->slot('a', 3, 1, false)];
        $result = $metrics->compare($before, $after, 'same', 'same');
        $this->assertSame(1, $result['paired_slots']);
        $this->assertSame(100.0, $result['delta']);
        $this->assertNull($metrics->compare($before, $after, 'old', 'new')['delta']);
        $this->assertNull($metrics->compare([], $after, 'same', 'same')['delta']);
    }

    public function test_entities_and_source_domains_are_deduplicated_per_answer(): void
    {
        $slot = $this->slot('a', 1, 1, true);
        $slot['mentions'] = [['entity_id' => 'x', 'name' => 'A', 'role' => 'supplier'], ['entity_id' => 'x', 'name' => 'A', 'role' => 'supplier'], ['entity_id' => 'y', 'name' => 'B', 'role' => 'marketplace']];
        $slot['sources'] = [['url' => 'https://example.org/a', 'domain' => 'example.org'], ['url' => 'https://example.org/b', 'domain' => 'example.org']];
        $result = (new VisibilityMetrics)->calculate([$slot], ['a'], 1, 1);
        $this->assertCount(2, $result['entities']);
        $this->assertSame(1, $result['entities'][0]['answers']);
        $this->assertSame(100.0, $result['entities'][1]['frequency']);
        $this->assertSame(2, $result['sources'][0]['url_count']);
        $this->assertSame(1, $result['sources'][0]['answers']);
        $this->assertNull($result['sources'][0]['presence']);
        $this->assertSame(1, $result['panel']['search']['unknown']);
    }

    public function test_paired_coverage_and_changes_are_separate_for_each_provider(): void
    {
        $before = [$this->slot('a', 1, 1, false), $this->slot('a', 2, 1, true), $this->slot('b', 1, 1, true)];
        $after = [$this->slot('a', 1, 1, true), $this->slot('b', 1, 1, false), $this->slot('b', 2, 1, true), $this->slot('c', 1, 1, true)];
        $result = (new VisibilityMetrics)->compare($before, $after, 'same', 'same');
        $this->assertSame(50.0, $result['coverage']);
        $this->assertSame(0.0, $result['delta']);
        $this->assertSame(100.0, $result['providers']['a']['coverage']);
        $this->assertSame(0.0, $result['providers']['a']['before']);
        $this->assertSame(100.0, $result['providers']['a']['after']);
        $this->assertSame(50.0, $result['providers']['b']['coverage']);
        $this->assertSame(-100.0, $result['providers']['b']['delta']);
        $this->assertNull($result['providers']['c']['delta']);
    }

    public function test_brand_identity_survives_frequency_aggregation(): void
    {
        $slot = $this->slot('a', 1, 1, true);
        $slot['mentions'] = [['entity_id' => 'brand', 'name' => 'Brand', 'role' => 'supplier', 'is_brand' => true]];
        $result = (new VisibilityMetrics)->calculate([$slot], ['a'], 1, 1);
        $this->assertTrue($result['entities'][0]['is_brand']);
    }

    public function test_company_role_uses_evidence_across_answers_independently_of_response_order(): void
    {
        $slots = [];
        foreach (['other', 'supplier', 'supplier', 'manufacturer'] as $index => $role) {
            $slot = $this->slot('a', $index + 1, 1, false);
            $slot['mentions'] = [['entity_id' => 'brand', 'name' => 'Company', 'role' => $role, 'is_brand' => false]];
            $slots[] = $slot;
        }
        $forward = (new VisibilityMetrics)->calculate($slots, ['a'], 4, 1)['entities'][0];
        $reverse = (new VisibilityMetrics)->calculate(array_reverse($slots), ['a'], 4, 1)['entities'][0];
        $this->assertSame($forward, $reverse);
        $this->assertSame('supplier', $forward['role']);
        $this->assertSame(['manufacturer' => 1, 'other' => 1, 'supplier' => 2], $forward['role_counts']);
        $this->assertTrue($forward['role_conflict']);
    }

    public function test_equal_conflicting_roles_are_not_resolved_by_first_answer(): void
    {
        $first = $this->slot('a', 1, 1, false);
        $first['mentions'] = [['name' => 'Company', 'role' => 'supplier']];
        $second = $this->slot('a', 2, 1, false);
        $second['mentions'] = [['name' => 'Company', 'role' => 'manufacturer']];
        $entity = (new VisibilityMetrics)->calculate([$first, $second], ['a'], 2, 1)['entities'][0];
        $this->assertNull($entity['role']);
        $this->assertTrue($entity['role_conflict']);
    }

    public function test_historical_question_counts_use_the_entire_planned_grid(): void
    {
        foreach ([12, 20, 30] as $size) {
            $slots = [];
            for ($i = 1; $i <= $size; $i++) {
                $slots[] = $this->slot('a', $i, 1, $i <= intdiv($size, 2));
            }
            $result = (new VisibilityMetrics)->calculate($slots, ['a'], $size, 1);
            $this->assertSame($size, $result['panel']['planned']);
            $this->assertSame($size, $result['panel']['valid']);
            $this->assertSame(50.0, $result['panel']['mention_rate']);
            $this->assertSame(100.0, $result['visibility']['coverage']);
        }
    }

    public function test_unknown_recommendations_roles_and_incomplete_entities_have_separate_denominators(): void
    {
        $one = $this->slot('a', 1, 1, true);
        $one['fact']['brand_recommended'] = true;
        $one['mentions_complete'] = false;
        $one['mentions'] = [['name' => 'Competitor', 'role' => null]];
        $two = $this->slot('a', 2, 1, true);
        $two['fact']['brand_recommended'] = null;
        $two['mentions_complete'] = false;
        $two['mentions'] = [];
        $three = $this->slot('a', 3, 1, false);
        $three['fact']['brand_recommended'] = false;
        $three['mentions_complete'] = true;
        $result = (new VisibilityMetrics)->calculate([$one, $two, $three], ['a'], 3, 1);
        $this->assertSame(2, $result['panel']['recommendation_denominator']);
        $this->assertSame(50.0, $result['panel']['recommended_rate']);
        $this->assertNull($result['panel']['share_of_voice']);
        $this->assertSame(2, $result['entities'][0]['denominator']);
        $this->assertSame(50.0, $result['entities'][0]['frequency']);
        $this->assertNull($result['entities'][0]['role']);
        $this->assertSame([], $result['entities'][0]['role_counts']);
    }

    public function test_evidence_source_fields_preserve_unknown_and_explicit_presence(): void
    {
        $one = $this->slot('a', 1, 1, true);
        $one['sources'] = [['domain' => 'example.org', 'url' => 'https://example.org/', 'source_type' => 'media', 'mentions_brand' => null, 'presence_evidence' => 'not_checked']];
        $result = (new VisibilityMetrics)->calculate([$one], ['a'], 1, 1);
        $this->assertSame('media', $result['sources'][0]['type']);
        $this->assertNull($result['sources'][0]['presence']);
        $this->assertSame(['not_checked'], $result['sources'][0]['presence_evidence']);
        $one['sources'][0]['mentions_brand'] = false;
        $result = (new VisibilityMetrics)->calculate([$one], ['a'], 1, 1);
        $this->assertFalse($result['sources'][0]['presence']);
    }

    public function test_possible_mentions_do_not_become_negative_measurements(): void
    {
        $one = $this->slot('a', 1, 1, true);
        $two = $this->slot('a', 2, 1, false);
        $two['fact']['mention_status'] = 'possible';
        $result = (new VisibilityMetrics)->calculate([$one, $two], ['a'], 2, 1);
        $this->assertSame(2, $result['panel']['valid']);
        $this->assertSame(1, $result['panel']['mention_denominator']);
        $this->assertSame(100.0, $result['panel']['mention_rate']);
        $this->assertSame(1, $result['panel']['possible']);
        $this->assertSame(1, $result['providers']['a']['complete_questions']);
        $this->assertNull($result['panel']['share_of_voice']);
    }

    private function slot(string $provider, int $question, int $run, bool $mentioned): array
    {
        return ['provider' => $provider, 'question_n' => $question, 'run_no' => $run, 'status' => 'ok', 'protocol_valid' => true, 'mentions_complete' => true, 'fact' => ['brand_mentioned' => $mentioned, 'brand_recommended' => false]];
    }
}
