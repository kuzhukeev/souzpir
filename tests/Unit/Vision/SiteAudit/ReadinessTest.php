<?php

namespace Tests\Unit\Vision\SiteAudit;

use App\Vision\SiteAudit\Metrics;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReadinessTest extends TestCase
{
    public function test_sixteen_confirmed_checks_keep_fixed_twenty_check_denominator(): void
    {
        $criteria = array_map(fn (int $id): array => ['id' => $id, 'score' => 1.0, 'weight' => 500], range(1, 16));

        $this->assertSame(['lower' => 80.0, 'upper' => 100.0, 'coverage' => 80.0, 'display' => '80–100'], Metrics::readiness($criteria));
    }

    public function test_unknown_is_distinct_from_a_confirmed_zero(): void
    {
        $unknown = Metrics::readiness([]);
        $failed = Metrics::readiness(array_map(fn (int $id): array => ['id' => $id, 'score' => 0.0], range(1, 20)));

        $this->assertSame(['lower' => 0.0, 'upper' => 100.0, 'coverage' => 0.0, 'display' => '0–100'], $unknown);
        $this->assertSame(['lower' => 0.0, 'upper' => 0.0, 'coverage' => 100.0, 'display' => '0'], $failed);
    }

    public function test_partial_bounds_preserve_unknown_mass_instead_of_normalizing_observed_subset(): void
    {
        $result = Metrics::readiness([['id' => 1, 'lower' => .25, 'unknown_weight' => .5]]);

        $this->assertSame(1.25, $result['lower']);
        $this->assertSame(98.75, $result['upper']);
        $this->assertSame(2.5, $result['coverage']);
    }

    public function test_twenty_confirmed_successes_are_exactly_one_hundred(): void
    {
        $criteria = array_map(fn (int $id): array => ['id' => $id, 'score' => 1.0], range(1, 20));

        $this->assertSame(['lower' => 100.0, 'upper' => 100.0, 'coverage' => 100.0, 'display' => '100'], Metrics::readiness($criteria));
    }

    #[DataProvider('invalidCriteria')]
    public function test_duplicate_identifiers_or_invalid_bounds_are_rejected(array $criteria): void
    {
        $this->expectException(InvalidArgumentException::class);

        Metrics::readiness($criteria);
    }

    public static function invalidCriteria(): array
    {
        return [
            'duplicate IDs' => [[['id' => 1, 'score' => 1.0], ['id' => 1, 'score' => 0.0]]],
            'ID below range' => [[['id' => 0, 'score' => .5]]],
            'ID above range' => [[['id' => 21, 'score' => .5]]],
            'negative score' => [[['id' => 1, 'score' => -.01]]],
            'score above one' => [[['id' => 1, 'score' => 1.01]]],
            'nonnumeric score' => [[['id' => 1, 'score' => 'unknown']]],
            'negative unknown mass' => [[['id' => 1, 'lower' => 0, 'unknown_weight' => -.01]]],
            'nonnumeric unknown mass' => [[['id' => 1, 'lower' => 0, 'unknown_weight' => 'unknown']]],
            'bounds exceed one' => [[['id' => 1, 'lower' => .6, 'unknown_weight' => .5]]],
            'nonfinite lower bound' => [[['id' => 1, 'lower' => NAN, 'unknown_weight' => 0]]],
            'nonfinite unknown mass' => [[['id' => 1, 'lower' => 0, 'unknown_weight' => NAN]]],
        ];
    }
}
