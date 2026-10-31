<?php

namespace App\Shell\Data\Contracts;

use Carbon\CarbonImmutable;

final readonly class ExportQuery
{
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $until) {}
}
