<?php

namespace App\Shell\Plans\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class UsageQuery
{
    /** @param list<string> $metrics */
    public function __construct(public DateTimeImmutable $from, public DateTimeImmutable $to, public array $metrics)
    {
        if ($from > $to || ! array_is_list($metrics)) {
            throw new InvalidArgumentException('Invalid usage query.');
        }
        foreach ($metrics as $metric) {
            if (! is_string($metric) || $metric === '') {
                throw new InvalidArgumentException('Invalid metric key.');
            }
        }
    }
}
