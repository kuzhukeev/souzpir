<?php

namespace App\Shell\Plans\Contracts;

final readonly class LimitValue
{
    public function __construct(public ?string $value, public string $unit, public bool $configured) {}
}
