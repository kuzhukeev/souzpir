<?php

namespace App\Shell\Plans\Contracts;

use DateTimeImmutable;

final readonly class UsageSnapshot
{
    /** @param array<string,array{value: int|float|string|null,unit: string,status: string}> $metrics */
    public function __construct(public DateTimeImmutable $asOf, public array $metrics) {}
}
