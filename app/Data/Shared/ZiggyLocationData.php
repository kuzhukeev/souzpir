<?php

namespace App\Data\Shared;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ZiggyLocationData extends Data
{
    public function __construct(public string $host, public string $pathname, public string $search) {}
}
