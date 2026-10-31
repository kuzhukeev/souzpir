<?php

namespace App\Shell\Data\Contracts;

final readonly class ExportRecord
{
    public function __construct(public string $type, public array $data) {}
}
