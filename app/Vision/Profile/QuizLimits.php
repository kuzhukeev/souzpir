<?php

namespace App\Vision\Profile;

use InvalidArgumentException;

/** Technical limits from R-V-01..03, independent of tariff entitlements. */
final class QuizLimits
{
    /** @param array{competitors: int, facts: int, questions: int} $limits */
    public function __construct(private readonly array $limits)
    {
        if (count($limits) !== 3 || array_diff(array_keys($limits), ['competitors', 'facts', 'questions']) !== []) {
            throw new InvalidArgumentException('Invalid quiz limit configuration.');
        }
        foreach (['competitors' => 10, 'facts' => 10, 'questions' => 15] as $name => $maximum) {
            if (! isset($limits[$name]) || ! is_int($limits[$name]) || $limits[$name] < 0 || $limits[$name] > $maximum) {
                throw new InvalidArgumentException('Invalid quiz limit configuration.');
            }
        }
    }

    public static function configured(): self
    {
        return new self(config('features.E8-02.defaults.limits'));
    }

    /** @return array{competitors: int, facts: int, questions: int} */
    public function values(): array
    {
        return $this->limits;
    }

    /** @param array<mixed> $items */
    public function permits(string $collection, array $items): bool
    {
        if (! array_key_exists($collection, $this->limits)) {
            throw new InvalidArgumentException('Unknown quiz collection.');
        }

        return array_is_list($items) && count($items) <= $this->limits[$collection];
    }
}
