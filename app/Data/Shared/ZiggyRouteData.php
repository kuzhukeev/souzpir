<?php

namespace App\Data\Shared;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ZiggyRouteData extends Data
{
    /** @param array<int, ZiggyMethod> $methods */
    public function __construct(public string $uri, public array $methods) {}
}
